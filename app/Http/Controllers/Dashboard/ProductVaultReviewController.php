<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\ProductFileVersion;
use App\Support\ProductReadiness;
use App\Support\ProductVault;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductVaultReviewController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->query('status', 'pending');
        abort_unless(in_array($status, ['pending', 'approved', 'rejected', 'disabled', 'all'], true), 404);
        $versions = ProductFileVersion::query()
            ->with(['product:id,public_id,user_id,title,type,status,license_definition_id,cover_media_id', 'uploader:id,name,public_id'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest()->paginate(10)->withQueryString();

        return view('dashboard.vault-versions', compact('versions', 'status'));
    }

    public function inspect(Request $request, ProductFileVersion $version): BinaryFileResponse
    {
        abort_unless(in_array($version->status, ['pending', 'approved'], true), 409);
        $path = ProductVault::verifiedPath($version->storage_path, $version->sha256, (int) $version->byte_size);
        abort_unless($path !== null, 409, 'Release integrity verification failed.');

        DB::transaction(function () use ($version, $request): void {
            $locked = ProductFileVersion::query()->lockForUpdate()->findOrFail($version->id);
            abort_unless(in_array($locked->status, ['pending', 'approved'], true), 409);
            $locked->update(['inspected_at' => now(), 'inspected_by' => $request->user()->id]);
            AuditEvent::record('vault.version.inspected', $request->user(), $request);
        });

        $type = $version->file_type;
        $name = 'bambodia-review-'.$version->id.'-'.$version->version.'.'.$type;

        return response()->download($path, $name, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
        ]);
    }

    public function decide(Request $request, ProductFileVersion $version): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected', 'disabled'])],
            'review_note' => ['nullable', 'string', 'max:2000', 'required_if:decision,rejected,disabled'],
            'confirm_review' => ['sometimes', 'accepted'],
        ]);

        DB::transaction(function () use ($version, $request, $data): void {
            $locked = ProductFileVersion::query()->lockForUpdate()->findOrFail($version->id);
            $allowed = $locked->status === 'pending'
                ? ['approved', 'rejected']
                : ($locked->status === 'approved' ? ['disabled'] : []);
            abort_unless(in_array($data['decision'], $allowed, true), 409, 'This release cannot enter the requested state.');

            if ($data['decision'] === 'approved') {
                abort_unless($locked->inspected_at !== null
                    && (int) $locked->inspected_by === (int) $request->user()->id
                    && $request->boolean('confirm_review'), 409,
                    'Inspect the original and confirm release review before approving.');
                $product = $locked->product()->firstOrFail();
                abort_unless($product->status === 'published', 409, 'The product is not currently published.');
                ProductReadiness::assertListing($product);
                if (ProductVault::verifiedPath($locked->storage_path, $locked->sha256, (int) $locked->byte_size) === null) {
                    throw ValidationException::withMessages(['artifact' => 'The release file is missing or has failed its SHA-256 integrity check.']);
                }
            }

            $locked->update([
                'status' => $data['decision'],
                'reviewed_at' => now(),
                'reviewed_by' => $request->user()->id,
                'review_note' => $data['review_note'] ?? null,
            ]);
            AuditEvent::record('vault.version.'.$data['decision'], $request->user(), $request);
        });

        return back()->with('status', 'Release marked '.$data['decision'].'.');
    }
}
