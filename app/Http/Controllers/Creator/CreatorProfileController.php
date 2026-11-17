<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\CreatorProfile;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceListing;
use App\Models\SavedListing;
use App\Models\User;
use App\Models\CreatorProfileItem;
use App\Models\CreatorProfileSection;
use App\Http\Controllers\ProfileEditorController;
use App\Support\CreatorBadgeAwards;
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
        [$search, $skill, $location] = array_map(
            function (string $key) use ($request): string {
                $value = $request->query($key);

                return is_string($value) ? mb_substr(trim($value), 0, 120) : '';
            },
            ['q', 'skill', 'location'],
        );
        $category = $request->query('category');
        $category = is_string($category) && preg_match('/^[0-9]{9}$/D', $category) ? $category : '';
        $visibleCreators = User::query()
            ->whereIn('role', ['freelancer', 'super_admin'])
            ->where(function ($query): void {
                $query->whereHas('creatorProfile', fn ($profile) => $profile->where('is_public', true))
                    ->orWhere(fn ($owner) => $owner->where('role', 'super_admin')
                        ->whereRaw('LOWER(email) = ?', [CreatorBadgeAwards::OWNER_EMAIL]));
            });

        $categories = MarketplaceCategory::query()->where('is_active', true)
            ->whereHas('listings', fn ($listings) => $listings->publiclyAvailable()
                ->whereIn('user_id', (clone $visibleCreators)->select('id')))
            ->orderBy('name')->orderBy('type')->get(['id', 'name', 'type', 'public_id']);
        $creators = $visibleCreators
            ->select(['id', 'public_id', 'name', 'email', 'role', 'created_at'])
            ->when($search !== '', fn ($query) => $query->where(function ($builder) use ($search) {
                $builder->whereLike('name', '%'.$search.'%')
                    ->orWhereHas('publicExpertise', fn ($skills) => $skills->whereLike('label', '%'.$search.'%'))
                    ->orWhereHas('creatorProfile', fn ($profile) => $profile->where('is_public', true)
                        ->where(fn ($text) => $text->whereLike('headline', '%'.$search.'%')
                            ->orWhereLike('biography', '%'.$search.'%')));
            }))
            ->when($skill !== '', fn ($query) => $query->whereHas('publicExpertise',
                fn ($skills) => $skills->whereLike('label', '%'.$skill.'%')))
            ->when($location !== '', fn ($query) => $query
                ->whereHas('creatorProfile', fn ($profile) => $profile->where('is_public', true))
                ->whereHas('userProfile', fn ($profile) => $profile->whereLike('public_location', '%'.$location.'%')))
            ->when($category !== '', fn ($query) => $query->whereHas('publishedListings.category',
                fn ($item) => $item->where('public_id', $category)->where('is_active', true)))
            ->with('creatorProfile:user_id,headline,biography,is_public')
            ->withCount(['publishedListings as public_listings_count'])
            ->orderBy('name')->orderBy('id')->paginate(10)->withQueryString();

        return view('creators.index', compact('creators', 'search', 'skill', 'location', 'category', 'categories'));
    }

    public function show(Request $request,string $creator): View
    {
        abort_unless(preg_match('/^[0-9]{8}$/D',$creator)===1,404);
        // Published marketplace work already names its creator publicly.
        // Such creators can have a limited achievements page without publishing
        // their optional private biography, website or account profile.
        $user=User::query()->where('public_id',$creator)
            ->whereIn('role',['freelancer','super_admin'])
            ->where(function($query): void {
                $query->whereHas('creatorProfile',fn($p)=>$p->where('is_public',true))
                    ->orWhereHas('publishedListings')
                    ->orWhere(fn($owner)=>$owner->where('role','super_admin')
                        ->whereRaw('LOWER(email) = ?', [CreatorBadgeAwards::OWNER_EMAIL]));
            })
            ->with(['creatorProfile', 'userProfile'])->firstOrFail();

        $listings=MarketplaceListing::query()->publiclyAvailable()
            ->where('user_id',$user->id)
            ->with(['coverAsset:id,public_id,alt_text,review_status','category:id,name'])
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
}
