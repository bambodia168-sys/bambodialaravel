<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductLicense;
use App\Models\ProductLicenseActivation;
use App\Support\ProductLicenseLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductLicenseApiController extends Controller
{
    public function activate(Request $request): JsonResponse
    {
        $this->requireTls($request);
        $data = $request->validate([
            'license_key'=>['required','string','regex:/^BAM-[A-F0-9]{48}$/'],
            'domain'=>['required','string','max:253'],
        ]);
        $domain = ProductLicenseLifecycle::domain($data['domain']);
        $license = ProductLicenseLifecycle::byKey($data['license_key']);
        if (! $license || ! ProductLicenseLifecycle::eligible($license)) {
            return $this->invalid();
        }
        $activation = ProductLicenseLifecycle::activate($license, $domain);
        return $this->valid($license, $activation->domain, 'activated');
    }

    public function validateInstallation(Request $request): JsonResponse
    {
        $this->requireTls($request);
        $data = $request->validate([
            'license_key'=>['required','string','regex:/^BAM-[A-F0-9]{48}$/'],
            'domain'=>['required','string','max:253'],
        ]);
        $domain = ProductLicenseLifecycle::domain($data['domain']);
        $license = ProductLicenseLifecycle::byKey($data['license_key']);
        if (! $license || ! ProductLicenseLifecycle::eligible($license)) return $this->invalid();
        $activation = ProductLicenseActivation::query()
            ->where('product_license_id',$license->id)
            ->where('domain',$domain)->where('status','active')->first();
        if (! $activation) return $this->invalid();
        $activation->forceFill(['last_validated_at'=>now()])->save();
        return $this->valid($license, $domain, 'valid');
    }

    private function requireTls(Request $request): void
    {
        // The installed script authenticates by a bearer secret. HTTP
        // transit is never acceptable outside an isolated test environment.
        if (app()->environment('production') && ! $request->isSecure()) {
            abort(403, 'License validation requires HTTPS.');
        }
    }

    private function valid(ProductLicense $license, string $domain, string $status): JsonResponse
    {
        return response()->json([
            'valid'=>true,
            'status'=>$status,
            'license_id'=>$license->public_id,
            'product_id'=>$license->product->public_id,
            'tier'=>$license->tier,
            'license_version'=>$license->definition->version,
            'release_version'=>$license->version->version,
            'domain'=>$domain,
            'expires_at'=>$license->expires_at?->toIso8601String(),
            'checked_at'=>now()->toIso8601String(),
            'check_again_after_seconds'=>21600,
        ])->header('Cache-Control','private, no-store, max-age=0');
    }

    private function invalid(): JsonResponse
    {
        // Same failure for nonexistent, revoked, expired and wrong-domain keys.
        return response()->json(['valid'=>false,'message'=>'License invalid, inactive, or not registered for this domain.'],403)
            ->header('Cache-Control','no-store');
    }
}
