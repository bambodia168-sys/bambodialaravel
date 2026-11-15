<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\LicenseDefinition;
use App\Models\ProductEntitlement;
use App\Models\ProductLicense;
use App\Models\ProductLicenseSupportRequest;
use App\Support\LicenseTerms;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LicenseManagerController extends Controller
{
    public function index(Request $request): View
    {
        $tab = $request->query('tab', $request->has('search') ? 'definitions' : ($request->has('support_page') ? 'renewals' : 'products'));
        abort_unless(in_array($tab, ['products', 'renewals', 'definitions'], true), 404);

        $data = match ($tab) {
            'definitions' => $this->definitions($request),
            'renewals' => ['supportRenewals' => ProductLicenseSupportRequest::query()
                ->with(['license.product:id,title,public_id,type', 'requester:id,public_id'])
                ->latest()->orderByDesc('id')->paginate(10, ['*'], 'support_page')->appends('tab', 'renewals')],
            'products' => $this->issued($request),
        };

        return view('dashboard.licenses', ['tab' => $tab, ...$data]);
    }

    private function definitions(Request $request): array
    {
        $search = mb_substr(trim($request->string('search')->toString()), 0, 120);
        $archived = $request->boolean('archived');
        $licenses = LicenseDefinition::query()
            ->withCount(['listings', 'extendedListings', 'issuedLicenses'])
            ->when($archived, fn ($query) => $query->where('status', 'archived'),
                fn ($query) => $query->where('status', '!=', 'archived'))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%')
                    ->orWhere('public_id', $search);
            }))
            ->latest()->orderByDesc('id')->paginate(10)
            ->appends(['tab' => 'definitions', 'search' => $search, 'archived' => $archived ? '1' : null]);

        return compact('licenses', 'search', 'archived');
    }

    private function issued(Request $request): array
    {
        $issuedSearch = mb_substr(trim($request->string('issued_search')->toString()), 0, 120);
        $issuedSort = $request->string('issued_sort', 'recent')->toString();
        if (! in_array($issuedSort, ['recent', 'oldest', 'updated', 'status'], true)) {
            $issuedSort = 'recent';
        }

        $issued = ProductLicense::query()
            ->with(['customer:id,public_id', 'product:id,title,public_id,user_id,status,slug,type,description',
                'version:id,version,status', 'definition:id,name,version',
                'entitlement:id,status,expires_at,revoked_at',
                'activations','events.actor:id,name'])
            ->when($issuedSearch !== '', function ($query) use ($issuedSearch): void {
                $query->where(function ($q) use ($issuedSearch): void {
                    $q->where('public_id','like','%'.$issuedSearch.'%')
                        ->orWhere('key_prefix','like','%'.$issuedSearch.'%')
                        ->orWhere('tier','like','%'.$issuedSearch.'%')
                        ->orWhere('status','like','%'.$issuedSearch.'%')
                        ->orWhereHas('product',function ($product) use ($issuedSearch): void {
                            $product->where('title','like','%'.$issuedSearch.'%')
                                ->orWhere('public_id','like','%'.$issuedSearch.'%');
                        })
                        ->orWhereHas('customer',function ($customer) use ($issuedSearch): void {
                            $customer->where('public_id','like','%'.$issuedSearch.'%')
                                ->orWhere('name','like','%'.$issuedSearch.'%');
                        })
                        ->orWhereHas('activations',fn ($activation) =>
                            $activation->where('domain','like','%'.$issuedSearch.'%'));
                });
            })
            ->when($issuedSort === 'recent',fn ($q) => $q->orderByDesc('id'))
            ->when($issuedSort === 'oldest',fn ($q) => $q->orderBy('id'))
            ->when($issuedSort === 'updated',fn ($q) => $q->orderByDesc('updated_at')->orderByDesc('id'))
            ->when($issuedSort === 'status',fn ($q) => $q->orderBy('status')->orderByDesc('id'))
            ->paginate(10, ['*'], 'issued_page')->appends(['tab' => 'products', 'issued_search' => $issuedSearch, 'issued_sort' => $issuedSort]);
        $issuable = $request->user()->role === 'super_admin'
            ? ProductEntitlement::query()->where('status','active')
                ->whereDoesntHave('license')
                ->whereHas('version',fn($q)=>$q->where('status','approved')
                    ->where('malware_scan_status','clean')->where('rights_review_status','verified')
                    ->whereHas('product',fn($p)=>$p->where('type','product')->where('status','published')))
                ->with(['recipient:id,public_id','version.product:id,title,type,status,license_definition_id,extended_license_definition_id,extended_price'])
                ->latest()->limit(100)->get()
            : collect();

        return compact('issued', 'issuable', 'issuedSearch', 'issuedSort');
    }

    public function store(Request $request): RedirectResponse
    {
        $values = $this->validated($request);

        if ($values['status'] === 'active' && ! $request->boolean('confirm_review')) {
            return back()->withInput()->withErrors(['status' => 'An administrator must confirm license review before publication.']);
        }

        $license = LicenseDefinition::query()->create([
            ...$values,
            'created_by' => $request->user()->id,
            'published_at' => $values['status'] === 'active' ? now() : null,
        ]);

        AuditEvent::record('license.definition.created', $request->user(), $request);
        if ($license->status === 'active') {
            AuditEvent::record('license.definition.activated', $request->user(), $request);
        }

        return $this->definitionsPage()->with('status', 'License definition created.');
    }

    public function update(Request $request, LicenseDefinition $license): RedirectResponse
    {
        $values = $this->validated($request, $license);

        if ($values['status'] === 'active' && ! $request->boolean('confirm_review')) {
            return back()->withInput()->withErrors(['status' => 'Confirm administrator legal review before activation.']);
        }

        DB::transaction(function () use ($request, $license, $values): void {
            $locked = LicenseDefinition::query()->lockForUpdate()->findOrFail($license->id);
            abort_if($locked->status === 'archived' || $locked->hasReferences(), 409,
                'This definition is archived or in use. Create a new definition for revised terms.');

            $wasActive = $locked->status === 'active';
            $locked->update([
                ...$values,
                'published_at' => $values['status'] === 'active' ? ($locked->published_at ?? now()) : null,
            ]);
            AuditEvent::record('license.definition.updated', $request->user(), $request);
            if (! $wasActive && $locked->status === 'active') {
                AuditEvent::record('license.definition.activated', $request->user(), $request);
            }
        }, 3);

        return $this->definitionsPage()->with('status', 'License definition updated.');
    }

    public function destroy(Request $request, LicenseDefinition $license): RedirectResponse
    {
        $request->validate(['confirm_remove' => ['accepted']]);

        $archived = DB::transaction(function () use ($request, $license): bool {
            $locked = LicenseDefinition::query()->lockForUpdate()->findOrFail($license->id);
            $inUse = $locked->hasReferences();
            if ($inUse) {
                abort_unless($locked->canArchiveLayoutTest(), 409,
                    'This definition is referenced by products or issued licenses and must be retained.');
                $locked->update(['status' => 'archived']);
            } else {
                $locked->delete();
            }
            AuditEvent::record($inUse ? 'license.definition.archived' : 'license.definition.deleted', $request->user(), $request);

            return $inUse;
        }, 3);

        return $this->definitionsPage()->with('status', $archived
            ? 'Layout-test definition archived and removed from the public license page.'
            : 'License definition deleted.');
    }

    private function definitionsPage(): RedirectResponse
    {
        return redirect()->route('dashboard.licenses.index', ['tab' => 'definitions']);
    }

    private function validated(Request $request, ?LicenseDefinition $license = null): array
    {
        $unique = Rule::unique('license_definitions', 'code');
        if ($license) {
            $unique->ignore($license->id);
        }

        $values = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_]{3,32}$/', $unique],
            'tier' => ['required', Rule::in(array_keys(LicenseDefinition::TIERS))],
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'version' => ['required', 'string', 'regex:/^[0-9]+\.[0-9]+\.[0-9]+$/', 'max:30'],
            'permissions' => ['nullable', 'string', 'max:50000', 'required_without:permissions_html'],
            'permissions_html' => ['nullable', 'string', 'max:150000'],
            'restrictions' => ['nullable', 'string', 'max:50000', 'required_without:restrictions_html'],
            'restrictions_html' => ['nullable', 'string', 'max:150000'],
            'status' => ['required', Rule::in(['draft', 'active'])],
        ]);

        foreach (['permissions', 'restrictions'] as $field) {
            $htmlField = $field.'_html';
            $html = filled($values[$htmlField] ?? null) ? LicenseTerms::sanitize($values[$htmlField]) : null;
            $plain = $html !== null ? LicenseTerms::plain($html) : trim($values[$field] ?? '');
            if (mb_strlen($plain) < 30 || mb_strlen($plain) > 50000) {
                throw ValidationException::withMessages([$field => 'Enter between 30 and 50,000 characters of license terms.']);
            }
            $values[$field] = $plain;
            $values[$htmlField] = $html ?? ($license && $license->$field === $plain ? $license->$htmlField : null);
        }

        return $values;
    }
}
