<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceCategory;
use App\Models\MediaAsset;
use App\Models\FeatureFlag;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ListingController extends Controller
{
    public function search(Request $request): View
    {
        abort_unless(FeatureFlag::isEnabled('marketplace_search'), 404);
        $type = (string) $request->query('type', '');
        $query = mb_substr(trim((string) $request->query('q', '')), 0, 120);
        $selectedCategory = (string) $request->query('category', '');
        abort_if($selectedCategory!=='' && preg_match('/^[0-9]{9}$/D',$selectedCategory)!==1,404);
        $categories=MarketplaceCategory::query()->where('is_active',true)
            ->orderBy('type')->orderBy('sort_order')->orderBy('name')->get(['id','public_id','type','name']);
        abort_if($type !== '' && ! in_array($type, MarketplaceListing::TYPES, true), 404);

        $listings = MarketplaceListing::query()
            ->with(['creator:id,name,public_id', 'coverAsset:id,public_id,review_status,alt_text', 'category:id,name,type'])
            ->where('status', 'published')
            ->where(function ($builder): void {
                $builder->where('type', '!=', 'product')
                    ->orWhere(function ($product): void {
                        $product->where('type', 'product')
                            ->whereHas('licenseDefinition', fn ($license) => $license->where('status', 'active'))
                            ->whereHas('coverAsset', fn ($cover) => $cover->where('review_status', 'approved'));
                    });
            })
            ->when($type !== '', fn ($builder) => $builder->where('type', $type))
            ->when($selectedCategory!=='',fn($builder)=>$builder->whereHas('category',fn($q)=>
                $q->where('public_id',$selectedCategory)->where('is_active',true)))
            ->when($query !== '', fn ($builder) => $builder->where(function ($builder) use ($query): void {
                $builder->where('title', 'like', '%'.$query.'%')
                    ->orWhere('description', 'like', '%'.$query.'%');
            }))
            ->latest()->paginate(10)->withQueryString();

        return view('marketplace.search', compact('listings', 'type', 'query','categories','selectedCategory'));
    }

    public function show(Request $request, string $type): View
    {
        abort_unless(in_array($type, MarketplaceListing::TYPES, true), 404);
        $raw = (string) $request->server('QUERY_STRING', '');
        abort_unless(preg_match('/^=(\d{9})-([a-z0-9-]+)$/', $raw, $match) === 1, 404);

        $listing = MarketplaceListing::query()
            ->with(['creator:id,name,public_id', 'licenseDefinition', 'coverAsset','category:id,name,type'])
            ->where('public_id', $match[1])
            ->where('slug', $match[2])
            ->where('type', $type)
            ->where('status', 'published')
            ->firstOrFail();

        if ($listing->type === 'product') {
            abort_unless($listing->licenseDefinition?->status === 'active'
                && $listing->coverAsset?->review_status === 'approved', 404);
        }

        return view('marketplace.detail', compact('listing'));
    }
}
