<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\CreatorProfile;
use App\Models\MarketplaceListing;
use App\Models\SavedListing;
use App\Models\User;
use App\Support\CreatorBadgeAwards;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CreatorProfileController extends Controller
{
    public function edit(Request $request): View
    {
        abort_unless($request->user()->isCreator(),403);
        $profile=CreatorProfile::query()->where('user_id',$request->user()->id)->first();
        $publishedCount=MarketplaceListing::query()->publiclyAvailable()
            ->where('user_id',$request->user()->id)->count();
        return view('account.creator-profile',compact('profile','publishedCount'));
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isCreator(),403);
        $data=$request->validate([
            'headline'=>['required','string','min:8','max:160'],
            'biography'=>['required','string','min:80','max:5000'],
            'website_url'=>['nullable','url','starts_with:https://','max:400'],
            'is_public'=>['required','boolean'],
        ]);

        CreatorProfile::query()->updateOrCreate(
            ['user_id'=>$request->user()->id],
            [
                'headline'=>$data['headline'],
                'biography'=>$data['biography'],
                'website_url'=>$data['website_url']??null,
                'is_public'=>(bool)$data['is_public'],
            ]
        );
        AuditEvent::record('creator.profile.updated',$request->user(),$request);

        return back()->with('status',$data['is_public']?'Public creator profile updated.':'Your creator profile is saved privately.');
    }

    public function directory(Request $request): View
    {
        $search=mb_substr(trim((string)$request->query('q','')),0,120);
        $creators=User::query()
            ->select(['id','public_id','name','email','role','created_at'])
            ->whereIn('role',['freelancer','super_admin'])
            ->where(function($query): void {
                $query->whereHas('creatorProfile',fn($p)=>$p->where('is_public',true))
                    ->orWhere(fn($owner)=>$owner->where('role','super_admin')
                        ->whereRaw('LOWER(email) = ?', [CreatorBadgeAwards::OWNER_EMAIL]));
            })
            ->when($search!=='',fn($q)=>$q->where(function($builder)use($search){
                $builder->where('name','like','%'.$search.'%')
                    ->orWhereHas('creatorProfile',fn($p)=>$p
                        ->where('is_public',true)
                        ->where(function($q)use($search){
                            $q->where('headline','like','%'.$search.'%')
                                ->orWhere('biography','like','%'.$search.'%');
                        }));
            }))
            ->with('creatorProfile:user_id,headline,biography,is_public')
            ->withCount(['publishedListings as public_listings_count'])
            ->orderBy('name')->paginate(10)->withQueryString();
        return view('creators.index',compact('creators','search'));
    }

    public function show(Request $request,string $creator): View
    {
        abort_unless(preg_match('/^[0-9]{8}$/D',$creator)===1,404);
        $user=User::query()->where('public_id',$creator)
            ->whereIn('role',['freelancer','super_admin'])
            ->where(function($query): void {
                $query->whereHas('creatorProfile',fn($p)=>$p->where('is_public',true))
                    ->orWhere(fn($owner)=>$owner->where('role','super_admin')
                        ->whereRaw('LOWER(email) = ?', [CreatorBadgeAwards::OWNER_EMAIL]));
            })
            ->with('creatorProfile')->firstOrFail();

        $listings=MarketplaceListing::query()->publiclyAvailable()
            ->where('user_id',$user->id)
            ->with(['coverAsset:id,public_id,alt_text,review_status','category:id,name'])
            ->latest()->paginate(10);
        $savedIds=$request->user()
            ? SavedListing::query()->where('user_id',$request->user()->id)
                ->whereIn('marketplace_listing_id',$listings->pluck('id'))
                ->pluck('marketplace_listing_id')->map(fn($id)=>(int)$id)->all()
            : [];
        return view('creators.show',[
            // Never reveal an unpublished biography or portfolio, including for the owner.
            'creator'=>$user,'profile'=>$user->creatorProfile?->is_public ? $user->creatorProfile : null,
            'listings'=>$listings,'savedIds'=>$savedIds,
        ]);
    }
}
