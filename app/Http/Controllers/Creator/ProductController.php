<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Support\BuilderContent;
use App\Support\BuilderFeaturedMedia;
use App\Models\AuditEvent;
use App\Models\FeatureFlag;
use App\Models\LicenseDefinition;
use App\Models\MarketplaceListing;
use App\Models\MediaAsset;
use App\Models\MarketplaceCategory;
use App\Support\ProductReadiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string)$request->query('status','all');
        abort_unless(in_array($status,['all','draft','pending','published','rejected'],true),404);
        $search = trim((string)$request->query('q',''));
        abort_unless(mb_strlen($search)<=120,404);
        $owned = MarketplaceListing::query()->where('type','product')
            ->where('user_id',$request->user()->id);
        $counts = (clone $owned)->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')->pluck('total','status');
        $products = (clone $owned)
            ->with(['licenseDefinition:id,name,version','coverAsset:id,public_id,alt_text,review_status'])
            ->when($status!=='all',fn($q)=>$q->where('status',$status))
            ->when($search!=='',fn($q)=>$q->where(function($inner)use($search){
                $inner->where('title','like','%'.$search.'%')
                    ->orWhere('public_id','like','%'.$search.'%');
            }))
            ->latest()->paginate(10)->withQueryString();

        $licenses = LicenseDefinition::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'version']);
        $assets = MediaAsset::query()->where('user_id', $request->user()->id)
            ->where('review_status', 'approved')
            ->latest()->get(['id', 'public_id', 'alt_text']);

        $categories=MarketplaceCategory::query()->where('type','product')
            ->where('is_active',true)->orderBy('sort_order')->orderBy('name')->get(['id','name']);
        return view('account.products', compact('products', 'licenses', 'assets','categories','status','search','counts'));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(FeatureFlag::isEnabled('creator_submissions'), 403);
        $data = $this->validated($request);

        if ($data['status'] === 'pending') {
            ProductReadiness::assertReady(
                $request->user()->id,
                $data['license_definition_id'] ?? null,
                $data['cover_media_id'] ?? null
            );
        }

        $product = MarketplaceListing::query()->create([
            ...$data,
            'user_id' => $request->user()->id,
            'type' => 'product',
            'currency' => 'USD',
        ]);

        AuditEvent::record('product.created', $request->user(), $request);

        if ($request->boolean('builder_mode')) {
            return redirect('/builder?='.$product->public_id)->with('status', 'Digital product saved.');
        }

        return redirect()->route('studio.products.index')->with('status', 'Digital product saved.');
    }

    public function update(Request $request, MarketplaceListing $product): RedirectResponse
    {
        abort_unless(FeatureFlag::isEnabled('creator_submissions'), 403);
        $data = $this->validated($request);

        DB::transaction(function () use ($request, $product, $data): void {
            $record = MarketplaceListing::query()->lockForUpdate()->findOrFail($product->id);
            abort_unless($record->type === 'product' && (int) $record->user_id === (int) $request->user()->id, 404);
            abort_unless(in_array($record->status, ['draft', 'rejected'], true), 409, 'Submitted or published products cannot be edited directly.');

            if ($data['status'] === 'pending') {
                ProductReadiness::assertReady(
                    (int) $record->user_id,
                    $data['license_definition_id'] ?? null,
                    $data['cover_media_id'] ?? null
                );
            }

            $record->update($data);
            AuditEvent::record('product.updated', $request->user(), $request);
        });

        return back()->with('status', 'Product changes saved.');
    }

    private function validated(Request $request): array
    {
        BuilderContent::prepare($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:4', 'max:180'],
            'description' => ['required', 'string', 'min:40', 'max:10000'],
            ...BuilderFeaturedMedia::rules(),
            'description_html' => ['nullable', 'string', 'max:60000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'license_terms' => ['nullable', 'string', 'max:20000'],
            'license_definition_id' => ['nullable', 'integer', Rule::exists('license_definitions', 'id')],
            'cover_media_id' => ['nullable', 'integer', Rule::exists('media_assets', 'id')],
            'category_id' => ['nullable','integer',Rule::exists('marketplace_categories','id')],
            'status' => ['required', Rule::in(['draft', 'pending'])],
        ]);

        // A draft must never be allowed to reference a different creator's private asset.
        if (! empty($data['cover_media_id'])
            && ! MediaAsset::query()->whereKey($data['cover_media_id'])
                ->where('user_id', $request->user()->id)->exists()) {
            throw ValidationException::withMessages([
                'cover_media_id' => 'Select a preview image from your own Media Library.',
            ]);
        }

        if(!empty($data['category_id']) && !MarketplaceCategory::query()
            ->whereKey($data['category_id'])->where('type','product')->where('is_active',true)->exists()){
            throw ValidationException::withMessages(['category_id'=>'Select an active product category.']);
        }
        BuilderFeaturedMedia::check($data, (int)$request->user()->id);
        return $data;
    }
}
