<?php

namespace App\Http\Controllers;

use App\Models\FeatureFlag;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\SavedListing;
use App\Models\SavedJob;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(Request $request): View
    {
        $searchEnabled=FeatureFlag::isEnabled('marketplace_search');
        $recentListings=collect();
        $categories=collect();
        $listingTotals=collect();
        $savedIds=[];

        if($searchEnabled){
            // Two latest of each type, interleaved, keep all four categories
            // represented within a compact 4-column, two-row homepage grid.
            $latestByType=[];
            foreach (MarketplaceListing::TYPES as $type) {
                $latestByType[$type]=MarketplaceListing::query()->publiclyAvailable()
                    ->where('type',$type)
                    ->with([
                        'creator:id,name,public_id',
                        'creator.creatorProfile:user_id,is_public',
                        'coverAsset:id,public_id,alt_text,review_status',
                        'featuredAsset:id,public_id,alt_text',
                        'category:id,name',
                    ])->latest('id')->limit(2)->get();
            }
            for ($index=0;$index<2;$index++) {
                foreach (MarketplaceListing::TYPES as $type) {
                    if (isset($latestByType[$type][$index])) {
                        $recentListings->push($latestByType[$type][$index]);
                    }
                }
            }

            $listingTotals=MarketplaceListing::query()->publiclyAvailable()
                ->selectRaw('type, COUNT(*) as total')->groupBy('type')->pluck('total','type');

            $categories=MarketplaceCategory::query()->where('is_active',true)
                ->orderBy('sort_order')->orderBy('name')->limit(8)->get();

            if($request->user()){
                $savedIds=SavedListing::query()->where('user_id',$request->user()->id)
                    ->whereIn('marketplace_listing_id',$recentListings->pluck('id'))
                    ->pluck('marketplace_listing_id')->map(fn($id)=>(int)$id)->all();
            }
        }

        $recentJobs=MarketplaceJob::query()->where('status','published')
            ->latest('id')->limit(5)->with('client:id,name,public_id')->get();
        $savedJobIds=$request->user()
            ? SavedJob::query()->where('user_id',$request->user()->id)
                ->whereIn('marketplace_job_id',$recentJobs->pluck('id'))
                ->pluck('marketplace_job_id')->map(fn($id)=>(int)$id)->all()
            : [];

        return view('home',compact(
            'searchEnabled','recentListings','categories','listingTotals','savedIds','recentJobs','savedJobIds'
        ));
    }
}
