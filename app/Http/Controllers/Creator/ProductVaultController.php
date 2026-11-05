<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\FeatureFlag;
use App\Models\MarketplaceListing;
use App\Models\ProductFileVersion;
use App\Support\ProductVault;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductVaultController extends Controller
{
    public function index(Request $request, MarketplaceListing $product): View
    {
        $product = $this->ownedProduct($request, $product);
        $versions = ProductFileVersion::query()
            ->where('marketplace_listing_id', $product->id)
            ->latest('id')->paginate(10);

        return view('account.product-versions', compact('product', 'versions'));
    }

    public function store(Request $request, MarketplaceListing $product): RedirectResponse
    {
        abort_unless(FeatureFlag::isEnabled('creator_submissions'), 403);
        $product = $this->ownedProduct($request, $product);
        abort_unless($product->status === 'published', 409, 'Publish the product catalog listing before uploading releases.');

        $data = $request->validate([
            'version' => ['required', 'string', 'max:32', 'regex:/^[0-9]+\.[0-9]+\.[0-9]+$/'],
            'release_notes' => ['required', 'string', 'min:20', 'max:3000'],
            'rights_attested' => ['required', 'accepted'],
            'rights_evidence' => ['required','string','min:30','max:3000'],
            'rights_source_url' => ['nullable','url','starts_with:https://','max:500'],
            'artifact' => ['required', 'file', 'max:'.ProductVault::MAX_UPLOAD_KB],
        ]);

        if (ProductFileVersion::query()->where('marketplace_listing_id', $product->id)
            ->where('version', $data['version'])->exists()) {
            throw ValidationException::withMessages(['version' => 'This product version already exists and cannot be replaced.']);
        }

        $file = $request->file('artifact');
        $inspection = ProductVault::inspect($file);
        $path = ProductVault::persist($file, (int) $request->user()->id, (int) $product->id, $inspection['type']);

        try {
            $stored = ProductVault::verifiedPath($path, $inspection['sha256'], (int) $file->getSize());
            if ($stored === null) {
                throw ValidationException::withMessages(['artifact' => 'The private file failed its integrity check.']);
            }

            DB::transaction(function () use ($product, $request, $file, $path, $data, $inspection): void {
                // Serializable version labels: unique(product,version) also blocks concurrent duplicates.
                $locked = MarketplaceListing::query()->lockForUpdate()->findOrFail($product->id);
                abort_unless($locked->type === 'product'
                    && (int) $locked->user_id === (int) $request->user()->id
                    && $locked->status === 'published', 409, 'The product is not currently publishable.');

                $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
                $name = preg_replace('/[\x00-\x1f\x7f]/', '', $name) ?: 'product-file.'.$inspection['type'];
                ProductFileVersion::query()->create([
                    'marketplace_listing_id' => $locked->id,
                    'uploaded_by' => $request->user()->id,
                    'version' => $data['version'],
                    'release_notes' => $data['release_notes'],
                    'original_filename' => mb_substr($name, 0, 180),
                    'storage_path' => $path,
                    'file_type' => $inspection['type'],
                    'byte_size' => $file->getSize(),
                    'sha256' => $inspection['sha256'],
                    'rights_attested_at' => now(),
                    'rights_evidence'=>$data['rights_evidence'],
                    'rights_source_url'=>$data['rights_source_url']??null,
                    'rights_review_status'=>'pending',
                    'malware_scan_status'=>'pending',
                    'status' => 'pending',
                ]);

                AuditEvent::record('vault.version.uploaded', $request->user(), $request);
            });
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }

        return redirect()->route('studio.vault.index', $product)
            ->with('status', 'Release uploaded privately. An administrator must verify it before downloads are possible.');
    }

    public function evidence(Request $request, MarketplaceListing $product, ProductFileVersion $version): RedirectResponse
    {
        $product=$this->ownedProduct($request,$product);
        abort_unless((int)$version->marketplace_listing_id===(int)$product->id,404);
        $data=$request->validate([
            'rights_evidence'=>['required','string','min:30','max:3000'],
            'rights_source_url'=>['nullable','url','starts_with:https://','max:500'],
        ]);
        DB::transaction(function()use($request,$product,$version,$data){
            $locked=ProductFileVersion::query()->lockForUpdate()->findOrFail($version->id);
            abort_unless((int)$locked->marketplace_listing_id===(int)$product->id
                && in_array($locked->status,['pending','approved'],true),409);

            $locked->update([
                'rights_evidence'=>$data['rights_evidence'],
                'rights_source_url'=>$data['rights_source_url']??null,
                'rights_attested_at'=>now(),
                'rights_review_status'=>'pending','rights_reviewed_by'=>null,
                'rights_reviewed_at'=>null,'rights_review_note'=>null,
            ]);
            AuditEvent::record('vault.rights.evidence_updated',$request->user(),$request);
        });
        return back()->with('status','Rights evidence updated. Independent review is required before downloads are permitted.');
    }

    private function ownedProduct(Request $request, MarketplaceListing $product): MarketplaceListing
    {
        abort_unless($product->type === 'product'
            && (int) $product->user_id === (int) $request->user()->id, 404);

        return $product;
    }
}
