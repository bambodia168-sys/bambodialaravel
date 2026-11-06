<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\LicenseDefinition;
use App\Models\MarketplaceListing;
use App\Models\ProductLicense;
use App\Models\ProductEntitlement;
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
            ->withCount('listings')
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%');
            }))
            ->latest()->paginate(10)->withQueryString();

        $issued = ProductLicense::query()
            ->with(['customer:id,public_id', 'product:id,title,public_id,user_id,status',
                'version:id,version,status', 'definition:id,name,version',
                'entitlement:id,status,expires_at,revoked_at',
                'activations','events.actor:id,name'])
            ->latest()->paginate(10,['*'],'issued_page')->withQueryString();
        $issuable = $request->user()->role === 'super_admin'
            ? ProductEntitlement::query()->where('status','active')
                ->whereDoesntHave('license')
                ->whereHas('version',fn($q)=>$q->where('status','approved')
                    ->where('malware_scan_status','clean')->where('rights_review_status','verified')
                    ->whereHas('product',fn($p)=>$p->where('type','product')->where('status','published')))
                ->with(['recipient:id,public_id','version.product:id,title,type,status,license_definition_id,extended_license_definition_id,extended_price'])
                ->latest()->limit(100)->get()
            : collect();

        return view('dashboard.licenses', compact('licenses', 'search','issued','issuable'));
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
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'version' => ['required', 'string', 'regex:/^[0-9]+\.[0-9]+\.[0-9]+$/', 'max:30'],
            'permissions' => ['required', 'string', 'min:30', 'max:50000'],
            'restrictions' => ['required', 'string', 'min:30', 'max:50000'],
            'status' => ['required', Rule::in(['draft', 'active'])],
        ]);
    }
}
