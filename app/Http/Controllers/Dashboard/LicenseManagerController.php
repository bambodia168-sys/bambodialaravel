<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\LicenseDefinition;
use App\Models\MarketplaceListing;
use App\Models\ProductLicense;
use App\Models\ProductEntitlement;
use App\Models\ProductLicenseSupportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LicenseManagerController extends Controller
{
    public function index(Request $request): View
    {
        $search = mb_substr(trim((string) $request->query('search', '')), 0, 120);
        $licenses = LicenseDefinition::query()
            ->withCount(['listings','extendedListings','issuedLicenses'])
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%');
            }))
            ->latest()->paginate(10)->withQueryString();

        $issuedSearch = mb_substr(trim((string) $request->query('issued_search', '')), 0, 120);
        $issuedSort = (string) $request->query('issued_sort', 'recent');
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
            ->paginate(10,['*'],'issued_page')->withQueryString();
        $supportRenewals=ProductLicenseSupportRequest::query()
            ->with(['license.product:id,title,public_id,type','license.customer:id,public_id','requester:id,public_id'])
            ->latest()->paginate(10,['*'],'support_page')->withQueryString();
        $issuable = $request->user()->role === 'super_admin'
            ? ProductEntitlement::query()->where('status','active')
                ->whereDoesntHave('license')
                ->whereHas('version',fn($q)=>$q->where('status','approved')
                    ->where('malware_scan_status','clean')->where('rights_review_status','verified')
                    ->whereHas('product',fn($p)=>$p->where('type','product')->where('status','published')))
                ->with(['recipient:id,public_id','version.product:id,title,type,status,license_definition_id,extended_license_definition_id,extended_price'])
                ->latest()->limit(100)->get()
            : collect();

        return view('dashboard.licenses', compact('licenses', 'search','issued','issuable','supportRenewals','issuedSearch','issuedSort'));
    }

    public function store(Request $request): RedirectResponse
    {
        $values = $this->validated($request, false);

        if ($values['status'] === 'active' && ! $request->boolean('confirm_review')) {
            return back()->withErrors(['status' => 'An administrator must confirm license review before publication.']);
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

        return back()->with('status', 'License definition created.');
    }

    public function update(Request $request, LicenseDefinition $license): RedirectResponse
    {
        $values = $this->validated($request, true, $license);

        if ($license->listings()->exists()
            || MarketplaceListing::query()->where('extended_license_definition_id',$license->id)->exists()
            || ProductLicense::query()->where('license_definition_id',$license->id)->exists()) {
            abort(409, 'This license is already referenced by listings. Create a new version instead of modifying existing terms.');
        }

        if ($values['status'] === 'active' && ! $request->boolean('confirm_review')) {
            return back()->withErrors(['status' => 'Confirm administrator legal review before activation.']);
        }

        $wasActive = $license->status === 'active';
        $license->update([
            ...$values,
            'published_at' => $values['status'] === 'active' ? ($license->published_at ?? now()) : null,
        ]);
        AuditEvent::record('license.definition.updated', $request->user(), $request);
        if (! $wasActive && $license->status === 'active') {
            AuditEvent::record('license.definition.activated', $request->user(), $request);
        }

        return back()->with('status', 'License definition updated.');
    }

    private function validated(Request $request, bool $updating, ?LicenseDefinition $license = null): array
    {
        $unique = Rule::unique('license_definitions', 'code');
        if ($updating && $license) {
            $unique->ignore($license->id);
        }

        return $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_]{3,32}$/', $unique],
            'tier' => ['required', Rule::in(array_keys(LicenseDefinition::TIERS))],
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'version' => ['required', 'string', 'regex:/^[0-9]+\.[0-9]+\.[0-9]+$/', 'max:30'],
            'permissions' => ['required', 'string', 'min:30', 'max:50000'],
            'restrictions' => ['required', 'string', 'min:30', 'max:50000'],
            'status' => ['required', Rule::in(['draft', 'active'])],
        ]);
    }
}
