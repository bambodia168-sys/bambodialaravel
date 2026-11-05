<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\MarketplaceListing;
use App\Models\ProductEntitlement;
use App\Models\ProductFileVersion;
use App\Models\User;
use App\Support\ProductReadiness;
use App\Support\ProductVault;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductEntitlementController extends Controller
{
    private const RECIPIENT_ROLES = ['customer', 'subscriber', 'freelancer'];

    public function index(Request $request): View
    {
        $entitlements = ProductEntitlement::query()
            ->with(['recipient:id,public_id,name,email,role', 'version:id,marketplace_listing_id,version,status'])
            ->latest()->paginate(10);
        $available = ProductFileVersion::query()
            ->where('status', 'approved')
            ->whereHas('product', fn ($q) => $q->where('type', 'product')->where('status', 'published'))
            ->with('product:id,title,public_id')->latest()->get();

        return view('dashboard.entitlements', compact('entitlements', 'available'));
    }

    public function grant(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'recipient_public_id' => ['required', 'regex:/^[0-9]{8}$/'],
            'product_file_version_id' => ['required', 'integer', Rule::exists('product_file_versions', 'id')],
            'grant_reason' => ['required', 'string', 'min:12', 'max:500'],
            'expires_days' => ['required', Rule::in(['7', '30', '90', '365'])],
            'confirm_no_payment' => ['required', 'accepted'],
        ]);

        DB::transaction(function () use ($request, $data): void {
            $recipient = User::query()
                ->where('public_id', $data['recipient_public_id'])->lockForUpdate()->firstOrFail();
            abort_unless(in_array($recipient->role, self::RECIPIENT_ROLES, true), 403, 'Only eligible personal accounts may receive downloads.');

            $version = ProductFileVersion::query()->lockForUpdate()->findOrFail($data['product_file_version_id']);
            abort_unless($version->isApproved(), 409, 'The release is not approved.');
            $product = MarketplaceListing::query()->whereKey($version->marketplace_listing_id)->lockForUpdate()->firstOrFail();
            abort_unless($product->type === 'product' && $product->status === 'published', 409);
            ProductReadiness::assertListing($product);
            if (ProductVault::verifiedPath($version->storage_path, $version->sha256, (int) $version->byte_size) === null) {
                throw ValidationException::withMessages(['product_file_version_id' => 'Release storage integrity failed.']);
            }

            $existing = ProductEntitlement::query()
                ->where('user_id', $recipient->id)
                ->where('product_file_version_id', $version->id)
                ->lockForUpdate()->first();
            abort_if($existing && $existing->isActive(), 409, 'This recipient already has active access to the selected version.');

            $attributes = [
                'user_id' => $recipient->id,
                'product_file_version_id' => $version->id,
                'granted_by' => $request->user()->id,
                'grant_reason' => $data['grant_reason'],
                'status' => 'active',
                'expires_at' => now()->addDays((int) $data['expires_days']),
                'revoked_at' => null,
                'revoked_by' => null,
                'revocation_reason' => null,
            ];
            if ($existing) {
                $existing->update($attributes);
            } else {
                ProductEntitlement::query()->create($attributes);
            }
            AuditEvent::record('vault.entitlement.granted', $request->user(), $request);
        });

        return back()->with('status', 'Manual access granted for the selected release. No payment was recorded or processed.');
    }

    public function revoke(Request $request, ProductEntitlement $entitlement): RedirectResponse
    {
        $data = $request->validate(['revocation_reason' => ['required', 'string', 'min:10', 'max:500']]);

        DB::transaction(function () use ($entitlement, $request, $data): void {
            $locked = ProductEntitlement::query()->lockForUpdate()->findOrFail($entitlement->id);
            abort_unless($locked->status === 'active', 409, 'This entitlement is already revoked.');
            $locked->update([
                'status' => 'revoked',
                'revoked_at' => now(),
                'revoked_by' => $request->user()->id,
                'revocation_reason' => $data['revocation_reason'],
            ]);
            AuditEvent::record('vault.entitlement.revoked', $request->user(), $request);
        });

        return back()->with('status', 'Download access revoked.');
    }
}
