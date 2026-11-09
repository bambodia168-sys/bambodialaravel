<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Support\BuilderGallery;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceCategory;
use App\Models\MediaAsset;
use App\Models\FeatureFlag;
use App\Models\SavedListing;
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
        $sort=(string)$request->query('sort','recent');
        abort_unless(in_array($sort,['recent','oldest','price_low','price_high','title'],true),404);
        $minPrice=(string)$request->query('min_price','');
        $maxPrice=(string)$request->query('max_price','');
        foreach([$minPrice,$maxPrice] as $amount){
            abort_if($amount!=='' && preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D',$amount)!==1,404);
        }
        abort_if($minPrice!=='' && $maxPrice!=='' && (float)$minPrice>(float)$maxPrice,404);

        $categories=MarketplaceCategory::query()->where('is_active',true)
            ->orderBy('type')->orderBy('sort_order')->orderBy('name')->get(['id','public_id','type','name']);
        abort_if($type !== '' && ! in_array($type, MarketplaceListing::TYPES, true), 404);

        $listings = MarketplaceListing::query()
            ->with(['creator:id,name,public_id', 'creator.creatorProfile:user_id,is_public,headline',
                'coverAsset:id,public_id,alt_text', 'featuredAsset:id,public_id,alt_text', 'category:id,name,type'])
            ->publiclyAvailable()
            ->when($type !== '', fn ($builder) => $builder->where('type', $type))
            ->when($selectedCategory!=='',fn($builder)=>$builder->whereHas('category',fn($q)=>
                $q->where('public_id',$selectedCategory)->where('is_active',true)))
            ->when($query !== '', fn ($builder) => $builder->where(function ($builder) use ($query): void {
                $builder->where('title', 'like', '%'.$query.'%')
                    ->orWhere('description', 'like', '%'.$query.'%');
            }))
            ->when($minPrice!=='',fn($q)=>$q->where('price','>=',$minPrice))
            ->when($maxPrice!=='',fn($q)=>$q->where('price','<=',$maxPrice))
            ->when($sort==='price_low'||$sort==='price_high',fn($q)=>$q
                ->orderByRaw('CASE WHEN price IS NULL THEN 1 ELSE 0 END')
                ->orderBy('price',$sort==='price_low'?'asc':'desc'))
            ->when($sort==='oldest',fn($q)=>$q->orderBy('created_at','asc'))
            ->when($sort==='title',fn($q)=>$q->orderBy('title','asc'))
            ->when($sort==='recent',fn($q)=>$q->orderBy('created_at','desc'))
            ->orderByDesc('id')->paginate(8)->withQueryString();

        $savedIds=$request->user()
            ? SavedListing::query()->where('user_id',$request->user()->id)
                ->whereIn('marketplace_listing_id',$listings->pluck('id'))
                ->pluck('marketplace_listing_id')->map(fn($id)=>(int)$id)->all()
            : [];
        return view('marketplace.search', compact('listings','type','query','categories',
            'selectedCategory','sort','minPrice','maxPrice','savedIds'));
    }

    public function show(Request $request, string $type): View
    {
        abort_unless(in_array($type, MarketplaceListing::TYPES, true), 404);
        $raw = (string) $request->server('QUERY_STRING', '');
        abort_unless(preg_match('/^=(\d{9})-([a-z0-9-]+)$/', $raw, $match) === 1, 404);

        $listing = MarketplaceListing::query()
            ->with(['creator:id,name,public_id','creator.creatorProfile:user_id,is_public,headline',
                'licenseDefinition','extendedLicenseDefinition','coverAsset','featuredAsset','category:id,name,type'])
            ->where('public_id', $match[1])
            ->where('slug', $match[2])
            ->where('type', $type)
            ->publiclyAvailable()->firstOrFail();

        if ($listing->type === 'product') {
            abort_unless($listing->licenseDefinition?->status === 'active'
                && $listing->coverAsset, 404);
        }

        $isSaved=$request->user()
            ? SavedListing::query()->where('user_id',$request->user()->id)
                ->where('marketplace_listing_id',$listing->id)->exists()
            : false;
        $relatedListings=MarketplaceListing::query()->publiclyAvailable()
            ->whereKeyNot($listing->id)->where('type',$listing->type)
            ->when($listing->category_id!==null,
                fn($q)=>$q->where('category_id',$listing->category_id))
            ->with(['creator:id,name,public_id','creator.creatorProfile:user_id,is_public,headline',
                'coverAsset:id,public_id,alt_text','category:id,name'])
            ->latest('id')->limit(8)->get();
        $savedRelatedIds=$request->user()
            ? SavedListing::query()->where('user_id',$request->user()->id)
                ->whereIn('marketplace_listing_id',$relatedListings->pluck('id'))
                ->pluck('marketplace_listing_id')->map(fn($id)=>(int)$id)->all()
            : [];

        $screenshots = BuilderGallery::approvedFor($listing->gallery_media_ids, (int)$listing->user_id);
        return view('marketplace.detail',compact('listing','isSaved','relatedListings','savedRelatedIds','screenshots'));
    }
}
