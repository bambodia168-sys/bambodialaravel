<?php

namespace App\Http\Controllers;

use App\Models\AccountNotification;
use App\Models\AuditEvent;
use App\Models\ContractDispute;
use App\Models\ContractMessage;
use App\Models\FreelanceContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContractCommunicationController extends Controller
{
    public function message(Request $request,FreelanceContract $contract): RedirectResponse
    {
        abort_unless($contract->isParticipant((int)$request->user()->id),404);
        $data=$request->validate(['body'=>['required','string','min:2','max:5000']]);
        DB::transaction(function()use($request,$contract,$data){
            $locked=FreelanceContract::query()->lockForUpdate()->findOrFail($contract->id);
            abort_unless(in_array($locked->status,['offered','active','disputed'],true),409);
            ContractMessage::query()->create([
                'freelance_contract_id'=>$locked->id,
                'sender_id'=>$request->user()->id,
                'body'=>$data['body'],'created_at'=>now(),
            ]);
            $recipient=(int)$locked->client_id===(int)$request->user()->id
                ? (int)$locked->freelancer_id : (int)$locked->client_id;
            AccountNotification::deliver($recipient,'contract.message.received',
                'New private contract message','The other party sent a message in your contract workspace.',
                '/contracts/'.$locked->public_id);
            AuditEvent::record('contract.message.sent',$request->user(),$request);
        });

        return back()->with('status','Private contract message sent.');
    }

    public function dispute(Request $request,FreelanceContract $contract): RedirectResponse
    {
        abort_unless($contract->isParticipant((int)$request->user()->id),404);
        $data=$request->validate([
            'category'=>['required',Rule::in(['scope','quality','timeline','conduct','other'])],
            'details'=>['required','string','min:50','max:6000'],
            'confirm_report'=>['required','accepted'],
        ]);

        DB::transaction(function()use($request,$contract,$data){
            $locked=FreelanceContract::query()->lockForUpdate()->findOrFail($contract->id);
            abort_unless($locked->status==='active',409);
            abort_if(ContractDispute::query()->where('freelance_contract_id',$locked->id)->exists(),
                409,'This agreement already has a dispute record.');
            ContractDispute::query()->create([
                'freelance_contract_id'=>$locked->id,'opened_by'=>$request->user()->id,
                'category'=>$data['category'],'details'=>$data['details'],'status'=>'open',
            ]);
            $locked->update(['status'=>'disputed']);
            $other=(int)$locked->client_id===(int)$request->user()->id
                ? (int)$locked->freelancer_id : (int)$locked->client_id;
            AccountNotification::deliver($other,'contract.dispute.opened',
                'Contract dispute reported',
                'The other participant requested an administrative review of the agreement.',
                '/contracts/'.$locked->public_id);
            AuditEvent::record('contract.dispute.opened',$request->user(),$request);
        });

        return back()->with('status','Dispute reported. Milestone decisions are paused until administrative review.');
    }
}
