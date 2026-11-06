<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\ProductDownloadEvent;
use App\Models\ProductEntitlement;
use App\Support\ProductReadiness;
use App\Support\ProductVault;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductDownloadController extends Controller
{
    public function index(Request $request): View
    {
        $entitlements = ProductEntitlement::query()
            ->where('user_id', $request->user()->id)
            ->with(['version.product:id,public_id,user_id,type,title,status,license_definition_id,cover_media_id',
                'license.entitlement:id,status,expires_at,revoked_at'])
            ->latest()->paginate(10);

        return view('account.downloads', compact('entitlements'));
    }

    public function download(Request $request, ProductEntitlement $entitlement): BinaryFileResponse
    {
        abort_unless((int) $entitlement->user_id === (int) $request->user()->id, 404);
        abort_unless(in_array($request->user()->role, ['customer', 'subscriber', 'freelancer'], true), 403);
        $entitlement->load('version.product');

        abort_unless($entitlement->isActive(), 403, 'Download access has expired or been revoked.');
        $version = $entitlement->version;
        abort_unless($version && $version->isApproved(), 403, 'The selected release is not available.');
        $product = $version->product;
        abort_unless($product && $product->type === 'product' && $product->status === 'published', 403);
        ProductReadiness::assertListing($product);

        $path = ProductVault::verifiedPath($version->storage_path, $version->sha256, (int) $version->byte_size);
        abort_unless($path !== null, 409, 'Stored release integrity could not be confirmed.');

        // Restrict the final filename to a predictable, non-user-controlled value.
        $name = 'bambodia-'.$product->public_id.'-'.$version->version.'.'.$version->file_type;
        $mime = match ($version->file_type) {
            'pdf' => 'application/pdf',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'zip' => 'application/zip',
            default => 'application/octet-stream',
        };

        DB::transaction(function () use ($entitlement, $request, $version): void {
            // Re-check revocation on the database row immediately before recording a download.
            $locked = ProductEntitlement::query()->lockForUpdate()->findOrFail($entitlement->id);
            abort_unless($locked->isActive(), 403, 'Download access is no longer active.');
            $current = $version->fresh();
            abort_unless($current && $current->isApproved(), 403);
            ProductDownloadEvent::query()->create([
                'product_entitlement_id' => $locked->id,
                'user_id' => $request->user()->id,
                'product_file_version_id' => $version->id,
                'sha256' => $version->sha256,
                'created_at' => now(),
            ]);
            AuditEvent::record('vault.download.served', $request->user(), $request);
        });

        return response()->download($path, $name, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Content-Security-Policy' => 'sandbox',
        ]);
    }
}
