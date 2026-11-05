<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\CreatorProfile;
use App\Models\MarketplaceListing;
use App\Models\User;
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

    public function show(string $creator): View
    {
        abort_unless(preg_match('/^[0-9]{8}$/D',$creator)===1,404);
        $user=User::query()->where('public_id',$creator)
            ->whereIn('role',['freelancer','super_admin'])
            ->whereHas('creatorProfile',fn($query)=>$query->where('is_public',true))
            ->with('creatorProfile')->firstOrFail();

        $listings=MarketplaceListing::query()->publiclyAvailable()
            ->where('user_id',$user->id)
            ->with(['coverAsset:id,public_id,alt_text,review_status','category:id,name'])
            ->latest()->paginate(10);
        return view('creators.show',['creator'=>$user,'profile'=>$user->creatorProfile,'listings'=>$listings]);
    }
}
