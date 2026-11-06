<?php

namespace App\Http\Controllers;

use App\Models\AccountNotification;
use App\Models\FreelanceContract;
use App\Models\JobProposal;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\SavedListing;
use App\Models\SavedJob;
use App\Models\ServiceInquiry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $id = $request->user()->public_id;
        if ($request->server('QUERY_STRING', '') !== '='.$id) {
            return redirect('/account?='.$id);
        }

        $user=$request->user();
        $stats=[
            'saved'=>SavedListing::query()->where('user_id',$user->id)->count(),
            'saved_jobs'=>SavedJob::query()->where('user_id',$user->id)->count(),
            'unread'=>AccountNotification::query()->where('user_id',$user->id)->whereNull('read_at')->count(),
            'contracts'=>FreelanceContract::query()->where(function($query)use($user){
                $query->where('client_id',$user->id)->orWhere(function($q)use($user){
                    $q->where('freelancer_id',$user->id)->where('status','!=','draft');
                });
            })->whereIn('status',['offered','active','disputed'])->count(),
        ];
        if(in_array($user->role,['customer','subscriber','super_admin'],true)){
            $stats['jobs']=MarketplaceJob::query()->where('client_id',$user->id)->count();
        }
        if($user->role==='freelancer'){
            $stats['proposals']=JobProposal::query()->where('freelancer_id',$user->id)->count();
        }
        if($user->isCreator()){
            $stats['listings']=MarketplaceListing::query()->where('user_id',$user->id)->count();
            $stats['client_inquiries']=ServiceInquiry::query()->where('creator_id',$user->id)->where('status','open')->count();
        }
        $activity=AccountNotification::query()->where('user_id',$user->id)->latest()->limit(5)->get();

        // Only agreements in which this account participates appear in the overview.
        // A freelancer cannot see the counterparty's private draft.
        $agreements=FreelanceContract::query()->where(function($query)use($user){
            $query->where('client_id',$user->id)
                ->orWhere(function($other)use($user){
                    $other->where('freelancer_id',$user->id)->where('status','!=','draft');
                });
        })->whereIn('status',['offered','active','disputed'])
          ->latest('updated_at')->limit(4)->get(['id','public_id','title','status','updated_at']);

        return view('account.index', compact('user','stats','activity','agreements'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'locale' => ['required', Rule::in(['en', 'zh', 'km', 'ko', 'ja', 'th', 'vi', 'de'])],
        ]);

        $request->user()->update($data);

        return redirect('/account?='.$request->user()->public_id)
            ->with('status', 'Your profile was updated.');
    }

    public function studio(Request $request): View|RedirectResponse
    {
        abort_unless($request->user()->isCreator(), 403);
        $id = $request->user()->public_id;
        // A dedicated Creator workspace; existing /studio links remain valid.
        if (! $request->routeIs('creator', 'localized.*.creator')
            && $request->server('QUERY_STRING', '') !== '='.$id) {
            return redirect('/studio?='.$id);
        }

        $user=$request->user();
        $stats=[
            'published'=>MarketplaceListing::query()->publiclyAvailable()->where('user_id',$user->id)
                ->where('type','!=','product')->count(),
            'pending'=>MarketplaceListing::query()->where('user_id',$user->id)
                ->where('type','!=','product')->where('status','pending')->count(),
            'inquiries'=>ServiceInquiry::query()->where('creator_id',$user->id)->where('status','open')->count(),
        ];
        $stats['products']=MarketplaceListing::query()->where('user_id',$user->id)
            ->where('type','product')->count();
        $stats['drafts']=MarketplaceListing::query()->where('user_id',$user->id)
            ->where('type','!=','product')->whereIn('status',['draft','rejected'])->count();
        $stats['product_drafts']=MarketplaceListing::query()->where('user_id',$user->id)
            ->where('type','product')->whereIn('status',['draft','rejected'])->count();
        $stats['product_pending']=MarketplaceListing::query()->where('user_id',$user->id)
            ->where('type','product')->where('status','pending')->count();
        $recentListings=MarketplaceListing::query()->where('user_id',$user->id)
            ->latest()->limit(5)->get(['id','type','title','status','updated_at']);
        $openInquiries=ServiceInquiry::query()->where('creator_id',$user->id)
            ->where('status','open')->latest('updated_at')->limit(4)
            ->get(['id','subject','updated_at']);

        return view('account.studio',compact('user','stats','recentListings','openInquiries'));
    }
}
