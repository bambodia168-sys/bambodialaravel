<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\MarketplaceListing;
use App\Models\SavedListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SavedListingController extends Controller
{
    public function index(Request $request): View
    {
        $saved=SavedListing::query()->where('user_id',$request->user()->id)
            ->with(['listing'=>function($query){
                $query->publiclyAvailable()->with([
                    'creator:id,name,public_id',
                    'creator.creatorProfile:user_id,is_public',
                    'coverAsset:id,public_id,alt_text,review_status',
                    'category:id,name',
                ]);
            }])->latest()->paginate(10);

        return view('account.saved-listings',compact('saved'));
    }

    public function store(Request $request,MarketplaceListing $listing): RedirectResponse
    {
        abort_unless(MarketplaceListing::query()->publiclyAvailable()->whereKey($listing->id)->exists(),404);
        $created=DB::table('saved_listings')->insertOrIgnore([
            'user_id'=>$request->user()->id,
            'marketplace_listing_id'=>$listing->id,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        if($created){
            AuditEvent::record('listing.saved',$request->user(),$request);
        }

        return back()->with('status','Listing saved to your private collection.');
    }

    public function destroy(Request $request,MarketplaceListing $listing): RedirectResponse
    {
        $deleted=SavedListing::query()->where('user_id',$request->user()->id)
            ->where('marketplace_listing_id',$listing->id)->delete();
        if($deleted){
            AuditEvent::record('listing.unsaved',$request->user(),$request);
        }

        return back()->with('status','Listing removed from your saved collection.');
    }
}
