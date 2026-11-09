<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ManualAccountReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class AccountVerificationController extends Controller
{
    public function index(Request $request): View
    {
        $search=mb_substr(trim((string)$request->query('search','')),0,120);
        $status=(string)$request->query('status','');
        abort_unless(in_array($status,['','unreviewed','verified','revoked'],true),404);
        $actor=$request->user();
        $users=User::query()
            ->when($actor->role!=='super_admin',fn($q)=>$q->whereIn('role',['customer','subscriber','freelancer']))
            ->when($search!=='',fn($q)=>$q->where(function($query)use($search){
                $query->where('name','like','%'.$search.'%')
                    ->orWhere('email','like','%'.$search.'%')
                    ->orWhere('username','like','%'.$search.'%')
                    ->orWhere('public_id','like','%'.$search.'%');
            }))
            ->when($status!=='',fn($q)=>$q->where('account_review_status',$status))
            ->orderByDesc('id')->paginate(10)->withQueryString();

        return view('dashboard.account-verifications',compact('users','search','status'));
    }

    public function show(Request $request, User $user): View
    {
        abort_unless(ManualAccountReview::canReview($request->user(),$user),403);
        $user->load('userProfile');
        $history=DB::table('user_account_review_events as events')
            ->leftJoin('users as reviewers','reviewers.id','=','events.reviewer_id')
            ->where('events.user_id',$user->id)
            ->orderByDesc('events.id')->limit(25)
            ->get(['events.decision','events.method','events.note','events.created_at','reviewers.name as reviewer_name']);
        return view('dashboard.account-verification-show',[
            'member'=>$user,'history'=>$history,
            'methods'=>ManualAccountReview::METHODS,
        ]);
    }

    public function verify(Request $request, User $user): RedirectResponse
    {
        abort_unless(ManualAccountReview::canReview($request->user(),$user),403);
        $data=$request->validate([
            'method'=>['required',Rule::in(array_keys(ManualAccountReview::METHODS))],
            'note'=>['required','string','min:12','max:1000'],
            'confirmation'=>['required','in:VERIFY'],
        ]);
        ManualAccountReview::apply($request,$user,'verified',$data['method'],$data['note']);
        return redirect()->route('dashboard.account-verifications.show',$user)
            ->with('status','Account manually verified. Email verification remains separate.');
    }

    public function revoke(Request $request, User $user): RedirectResponse
    {
        abort_unless(ManualAccountReview::canReview($request->user(),$user),403);
        $data=$request->validate([
            'note'=>['required','string','min:12','max:1000'],
            'confirmation'=>['required','in:REVOKE'],
        ]);
        ManualAccountReview::apply($request,$user,'revoked','revocation',$data['note']);
        return redirect()->route('dashboard.account-verifications.show',$user)
            ->with('status','Manual account verification revoked; audit history retained.');
    }
}