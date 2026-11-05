<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\PrivacyRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PrivacyRequestController extends Controller
{
    public function index(Request $request): View
    {
        $requests=PrivacyRequest::query()->where('user_id',$request->user()->id)
            ->latest()->paginate(10);
        return view('account.privacy-requests',compact('requests'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data=$request->validate([
            'category'=>['required',Rule::in(PrivacyRequest::CATEGORIES)],
            'details'=>['required','string','min:40','max:6000'],
            'confirm_submission'=>['required','accepted'],
        ]);
        $record=DB::transaction(function()use($request,$data): PrivacyRequest {
            $record=PrivacyRequest::query()->create([
                'user_id'=>$request->user()->id,
                'category'=>$data['category'],
                'details'=>$data['details'],
                'status'=>'submitted',
            ]);
            AuditEvent::record('privacy.request.submitted',$request->user(),$request);
            return $record;
        });
        return redirect()->route('privacy.show',$record)
            ->with('status','Your privacy request was recorded and is awaiting administrative review.');
    }

    public function show(Request $request,PrivacyRequest $privacyRequest): View
    {
        abort_unless((int)$privacyRequest->user_id===(int)$request->user()->id,404);
        return view('account.privacy-request',compact('privacyRequest'));
    }

    public function close(Request $request,PrivacyRequest $privacyRequest): RedirectResponse
    {
        abort_unless((int)$privacyRequest->user_id===(int)$request->user()->id,404);
        $request->validate(['confirm_close'=>['required','accepted']]);
        DB::transaction(function()use($request,$privacyRequest){
            $locked=PrivacyRequest::query()->lockForUpdate()->findOrFail($privacyRequest->id);
            abort_unless((int)$locked->user_id===(int)$request->user()->id,404);
            abort_unless($locked->status==='responded',409);
            $locked->update(['status'=>'closed','closed_at'=>now()]);
            AuditEvent::record('privacy.request.closed',$request->user(),$request);
        });
        return back()->with('status','Your request was marked closed. The response history remains retained.');
    }
}
