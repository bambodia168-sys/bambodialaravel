<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AccountNotification;
use App\Models\AuditEvent;
use App\Models\PrivacyRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PrivacyRequestManagerController extends Controller
{
    public function index(Request $request): View
    {
        $status=(string)$request->query('status','all');
        abort_unless(in_array($status,['all','submitted','in_review','responded','closed'],true),404);
        $requests=PrivacyRequest::query()->with(['owner:id,name,public_id','reviewer:id,name'])
            ->when($status!=='all',fn($q)=>$q->where('status',$status))
            ->latest()->paginate(10)->withQueryString();
        return view('dashboard.privacy-requests',compact('requests','status'));
    }

    public function inspect(Request $request,PrivacyRequest $privacyRequest): View
    {
        abort_unless((int)$privacyRequest->user_id!==(int)$request->user()->id,403,
            'You may not administratively review your own request.');
        $privacyRequest->load('owner:id,name,public_id','reviewer:id,name');
        AuditEvent::record('privacy.request.inspected',$request->user(),$request);
        return view('dashboard.privacy-request',compact('privacyRequest'));
    }

    public function update(Request $request,PrivacyRequest $privacyRequest): RedirectResponse
    {
        $data=$request->validate([
            'decision'=>['required',Rule::in(['in_review','responded'])],
            'response_note'=>['required_if:decision,responded','nullable','string','min:30','max:6000'],
        ]);
        DB::transaction(function()use($request,$privacyRequest,$data){
            $locked=PrivacyRequest::query()->lockForUpdate()->findOrFail($privacyRequest->id);
            abort_unless((int)$locked->user_id!==(int)$request->user()->id,403);
            if($data['decision']==='in_review'){
                abort_unless($locked->status==='submitted',409);
                $locked->update([
                    'status'=>'in_review','reviewed_by'=>$request->user()->id,
                    'review_started_at'=>now(),
                ]);
            }else{
                abort_unless($locked->status==='in_review',409);
                $locked->update([
                    'status'=>'responded','reviewed_by'=>$request->user()->id,
                    'response_note'=>$data['response_note'],'responded_at'=>now(),
                ]);
                AccountNotification::deliver((int)$locked->user_id,
                    'privacy.request.responded',
                    'A response to your privacy request is available',
                    'An administrator recorded a response. Please review the response before closing your case.',
                    '/privacy-requests/'.$locked->public_id);
            }
            AuditEvent::record('privacy.request.'.$data['decision'],$request->user(),$request);
        });
        return back()->with('status','Request handling state recorded; no automatic data deletion or export was performed.');
    }
}
