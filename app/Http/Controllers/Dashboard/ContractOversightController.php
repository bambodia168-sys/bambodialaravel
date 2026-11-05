<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AccountNotification;
use App\Models\AuditEvent;
use App\Models\ContractDispute;
use App\Models\FreelanceContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ContractOversightController extends Controller
{
    public function index(Request $request): View
    {
        $status=(string)$request->query('status','all');
        abort_unless(in_array($status,['all','draft','offered','active','disputed','completed','declined','cancelled'],true),404);
        $contracts=FreelanceContract::query()
            ->with(['client:id,name,public_id','freelancer:id,name,public_id','dispute'])
            ->withCount('milestones')
            ->when($status!=='all',fn($q)=>$q->where('status',$status))
            ->latest()->paginate(10)->withQueryString();
        $statusCounts=FreelanceContract::query()->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')->pluck('total','status');
        return view('dashboard.contracts',compact('contracts','status','statusCounts'));
    }

    public function disputes(Request $request): View
    {
        $status=(string)$request->query('status','all');
        abort_unless(in_array($status,['all','open','under_review','resolved'],true),404);
        $disputes=ContractDispute::query()
            ->with(['reporter:id,name,public_id','contract.client:id,name,public_id','contract.freelancer:id,name,public_id'])
            ->when($status!=='all',fn($q)=>$q->where('status',$status))
            ->latest()->paginate(10)->withQueryString();
        return view('dashboard.contract-disputes',compact('disputes','status'));
    }

    public function inspect(Request $request,ContractDispute $dispute): View
    {
        $dispute->load(['contract.client:id,name,public_id','contract.freelancer:id,name,public_id','reporter:id,name,public_id']);
        abort_unless(! $dispute->contract->isParticipant((int)$request->user()->id),403,
            'An administrator may not review their own contract dispute.');
        $contract=$dispute->contract;
        $milestones=$contract->milestones()->with(['submissions.review'])->get();
        $messages=$contract->messages()->with('author:id,name')->oldest('id')->paginate(10);
        AuditEvent::record('contract.dispute.inspected',$request->user(),$request);
        return view('dashboard.contract-dispute-detail',compact('dispute','contract','milestones','messages'));
    }

    public function decide(Request $request,ContractDispute $dispute): RedirectResponse
    {
        $data=$request->validate([
            'decision'=>['required',Rule::in(['under_review','resolved'])],
            'disposition'=>['required_if:decision,resolved','nullable',Rule::in(['resume','cancel'])],
            'resolution_note'=>['required_if:decision,resolved','nullable','string','min:30','max:5000'],
        ]);
        DB::transaction(function()use($request,$dispute,$data){
            $locked=ContractDispute::query()->lockForUpdate()->findOrFail($dispute->id);
            $contract=FreelanceContract::query()->lockForUpdate()->findOrFail($locked->freelance_contract_id);
            abort_unless(! $contract->isParticipant((int)$request->user()->id),403);
            abort_unless($contract->status==='disputed',409);

            if($data['decision']==='under_review'){
                abort_unless($locked->status==='open',409);
                $locked->update(['status'=>'under_review','reviewed_by'=>$request->user()->id]);
            }else{
                abort_unless(in_array($locked->status,['open','under_review'],true),409);
                $locked->update([
                    'status'=>'resolved','reviewed_by'=>$request->user()->id,
                    'resolution_note'=>$data['resolution_note'],
                    'disposition'=>$data['disposition'],'resolved_at'=>now(),
                ]);
                $contract->update([
                    'status'=>$data['disposition']==='resume'?'active':'cancelled',
                    'cancelled_at'=>$data['disposition']==='cancel'?now():null,
                ]);
                foreach([$contract->client_id,$contract->freelancer_id] as $userId){
                    AccountNotification::deliver((int)$userId,'contract.dispute.resolved',
                        'Contract dispute review completed',
                        'The administrative case was closed with disposition: '.$data['disposition'].'.',
                        '/contracts/'.$contract->public_id);
                }
            }
            AuditEvent::record('contract.dispute.'.$data['decision'],$request->user(),$request);
        });
        return back()->with('status','Dispute review decision recorded. This is not a judicial ruling or payment settlement.');
    }
}
