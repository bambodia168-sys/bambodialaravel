<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\CreatorBadge;
use App\Models\CreatorProfile;
use App\Models\MarketplaceListing;
use App\Models\SavedListing;
use App\Models\User;
use App\Models\CreatorProfileItem;
use App\Models\CreatorProfileSection;
use App\Http\Controllers\ProfileEditorController;
use App\Support\CreatorBadgeAwards;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CreatorProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return app(ProfileEditorController::class)->editSelf($request);
    }

    public function update(Request $request): RedirectResponse
    {
        return app(ProfileEditorController::class)->updateSelf($request);
    }

    public function directory(Request $request): View
    {
        $search=mb_substr(trim((string)$request->query('q','')),0,120);
        $creators=$this->publicCreators()
            ->select(['id','public_id','name','email','role','created_at'])
            ->when($search!=='',fn($q)=>$q->where(function($builder)use($search){
                $builder->where('name','like','%'.$search.'%')
                    ->orWhereHas('creatorProfile',fn($p)=>$p
                        ->where('is_public',true)
                        ->where(function($q)use($search){
                            $q->where('headline','like','%'.$search.'%')
                                ->orWhere('biography','like','%'.$search.'%');
                        }));
            }))
            ->with([
                'creatorProfile:user_id,headline,is_public',
                'userProfile:user_id,avatar_path,public_location',
                'publicExpertise' => fn ($skills) => $skills->select('id', 'user_id', 'label')->limit(3),
            ])
            ->withCount(['publishedListings as public_listings_count'])
            ->orderBy('name')->orderBy('id')->paginate(10)->withQueryString();
        $badgeCatalog=CreatorBadge::query()->where('enabled', true)->orderBy('sort_order')->get();

        return view('creators.index',compact('creators','search','badgeCatalog'));
    }

    public function show(Request $request,string $creator): View
    {
        abort_unless(preg_match('/^[0-9]{8}$/D',$creator)===1,404);
        // Published marketplace work already names its creator publicly.
        // Such creators can have a limited achievements page without publishing
        // their optional private biography, website or account profile.
        $user=$this->publicCreators()->where('public_id',$creator)
            ->with(['creatorProfile', 'userProfile'])->firstOrFail();

        $listings=MarketplaceListing::query()->publiclyAvailable()
            ->where('user_id',$user->id)
            ->with(['coverAsset:id,public_id,alt_text,review_status','category:id,public_id,name,type,is_active','creator:id,name,public_id'])
            ->latest()->paginate(10);
        $savedIds=$request->user()
            ? SavedListing::query()->where('user_id',$request->user()->id)
                ->whereIn('marketplace_listing_id',$listings->pluck('id'))
                ->pluck('marketplace_listing_id')->map(fn($id)=>(int)$id)->all()
            : [];
        $publishedProfile = $user->creatorProfile?->is_public ? $user->creatorProfile : null;
        $items = $publishedProfile ? CreatorProfileItem::query()->where('user_id', $user->id)
            ->where('is_visible', true)->orderBy('sort_order')->orderBy('id')->get()->groupBy('kind') : collect();
        $sections = $publishedProfile
            ? CreatorProfileSection::query()->where('user_id', $user->id)
                ->where('is_visible', true)->orderBy('sort_order')->orderBy('id')->get()
            : collect();
        $contactListing = MarketplaceListing::query()->publiclyAvailable()
            ->where('user_id', $user->id)->whereIn('type', ['service','freelance','project'])
            ->where('title','not like','[LAYOUT TEST] %')->latest()->first();
        return view('creators.show',[
            // Never reveal an unpublished biography or portfolio, including for the owner.
            'creator'=>$user,'profile'=>$publishedProfile,
            'items'=>$items,'sections'=>$sections,'contactListing'=>$contactListing,
            'listings'=>$listings,'savedIds'=>$savedIds,
        ]);
    }

    private function publicCreators(): Builder
    {
        return User::query()->where(function (Builder $creators): void {
            $creators->discoverableCreator(includeAdministratorProfiles: true)
                ->orWhere(fn (Builder $owner) => $owner->where('role', 'super_admin')
                    ->whereRaw('LOWER(email) = ?', [CreatorBadgeAwards::OWNER_EMAIL]));
        });
    }
}
