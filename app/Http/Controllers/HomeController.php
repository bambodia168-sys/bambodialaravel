<?php

namespace App\Http\Controllers;

use App\Models\ContentEntry;
use App\Models\CreatorBadge;
use App\Models\FeatureFlag;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\SavedListing;
use App\Models\SavedJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(Request $request): View
    {
        $searchEnabled=FeatureFlag::isEnabled('marketplace_search');
        $recentListings=new Collection();
        $freelancers=collect();
        $badgeCatalog=collect();
        $listingTotals=collect();
        $savedIds=[];

        if($searchEnabled){
            // Keep the newest listings from each type represented and fill
            // spare slots from the types with more published listings.
            $listingLimit=12;
            $latestByType=[];
            foreach (MarketplaceListing::TYPES as $type) {
                $latestByType[$type]=MarketplaceListing::query()->publiclyAvailable()
                    ->where('type',$type)
                    ->latest('id')->limit($listingLimit)->get();
            }
            for ($index=0;$index<$listingLimit;$index++) {
                foreach ($latestByType as $listings) {
                    if (!isset($listings[$index])) {
                        continue;
                    }

                    $recentListings->push($listings[$index]);
                    if ($recentListings->count()===$listingLimit) {
                        break 2;
                    }
                }
            }
            $recentListings=$recentListings->shuffle()->load([
                'creator:id,name,public_id',
                'creator.creatorProfile:user_id,is_public',
                'coverAsset:id,public_id,alt_text,review_status',
                'featuredAsset:id,public_id,alt_text',
                'category:id,name',
            ]);

            $listingTotals=MarketplaceListing::query()->publiclyAvailable()
                ->selectRaw('type, COUNT(*) as total')->groupBy('type')->pluck('total','type');

            // Give published work priority while keeping new public portfolios discoverable.
            $freelancers=User::query()
                ->select(['id','public_id','name','email','role','created_at'])
                ->discoverableCreator()
                ->with([
                    'creatorProfile:user_id,headline,is_public',
                    'userProfile:user_id,avatar_path,public_location',
                    'publicExpertise' => fn ($skills) => $skills->select('id', 'user_id', 'label')->limit(3),
                ])
                ->withCount(['publishedListings as public_listings_count'])
                ->orderByDesc('public_listings_count')
                ->orderByDesc('id')
                ->paginate(10, pageName: 'freelancers_page')->withQueryString()->fragment('bambodia-freelancers');

            if ($freelancers->isNotEmpty()) {
                $badgeCatalog=CreatorBadge::query()->where('enabled', true)->orderBy('sort_order')->get();
            }

            if($request->user()){
                $savedIds=SavedListing::query()->where('user_id',$request->user()->id)
                    ->whereIn('marketplace_listing_id',$recentListings->pluck('id'))
                    ->pluck('marketplace_listing_id')->map(fn($id)=>(int)$id)->all();
            }
        }

        // A bounded feed of real, published articles; future and drafts stay private.
        $recentPosts=ContentEntry::query()
            ->where('type','post')->where('status','published')
            ->whereNotNull('published_at')->where('published_at','<=',now())
            ->with(['featuredMedia:id,public_id,mime_type,alt_text', 'author:id,name'])
            ->latest('published_at')->orderByDesc('id')
            ->limit(10)->get();

        $recentJobs=MarketplaceJob::query()->where('status','published')
            ->latest('id')->limit(5)->with('client:id,name,public_id', 'client.creatorProfile:user_id,is_public', 'client.userProfile:user_id,avatar_path')->get();
        $savedJobIds=$request->user()
            ? SavedJob::query()->where('user_id',$request->user()->id)
                ->whereIn('marketplace_job_id',$recentJobs->pluck('id'))
                ->pluck('marketplace_job_id')->map(fn($id)=>(int)$id)->all()
            : [];

        return view('home',compact(
            'searchEnabled','recentListings','freelancers','badgeCatalog','listingTotals','savedIds','recentJobs','savedJobIds','recentPosts'
        ));
    }
}
