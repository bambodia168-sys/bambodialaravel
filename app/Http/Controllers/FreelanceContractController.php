<?php

namespace App\Http\Controllers;

use App\Models\AccountNotification;
use App\Models\AuditEvent;
use App\Models\ContractConsent;
use App\Models\ContractMilestone;
use App\Models\FreelanceContract;
use App\Models\JobProposal;
use App\Models\MarketplaceJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FreelanceContractController extends Controller
{
    public function index(Request $request): View
    {
        $contracts=FreelanceContract::query()
            ->where(function($q)use($request){
                $q->where('client_id',$request->user()->id)
                    ->orWhere('freelancer_id',$request->user()->id);
            })
            ->with(['client:id,name,public_id','freelancer:id,name,public_id'])
            ->latest()->paginate(10);
        return view('contracts.index',compact('contracts'));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(in_array($request->user()->role,['customer','subscriber','super_admin'],true),403);
        $data=$request->validate([
            'proposal_id'=>['required','integer',Rule::exists('job_proposals','id')],
            'title'=>['required','string','min:8','max:180'],
            'scope'=>['required','string','min:80','max:12000'],
            'terms'=>['required','string','min:60','max:12000'],
            'agreed_budget'=>['required','numeric','min:0','max:9999999999.99','decimal:0,2'],
        ]);

        $contract=DB::transaction(function()use($request,$data): FreelanceContract {
            $proposal=JobProposal::query()->lockForUpdate()->findOrFail($data['proposal_id']);
            $job=MarketplaceJob::query()->findOrFail($proposal->marketplace_job_id);
            abort_unless((int)$job->client_id===(int)$request->user()->id,404);
            abort_unless(in_array($job->status,['published','closed'],true)
                && $proposal->status==='shortlisted',409,
                'Only shortlisted applications for an approved job may receive an agreement.');
            abort_if(FreelanceContract::query()->where('job_proposal_id',$proposal->id)->exists(),409,
                'An agreement already exists for this application.');

            $contract=FreelanceContract::query()->create([
                'job_proposal_id'=>$proposal->id,
                'client_id'=>$job->client_id,
                'freelancer_id'=>$proposal->freelancer_id,
                'title'=>$data['title'],
                'scope'=>$data['scope'],
                'terms'=>$data['terms'],
                'agreed_budget'=>$data['agreed_budget'],
                'currency'=>'USD',
                'status'=>'draft',
            ]);
            AuditEvent::record('contract.draft.created',$request->user(),$request);
            return $contract;
        });

        return redirect()->route('contracts.show',$contract)
            ->with('status','Agreement saved as a private draft. Add every milestone before offering it to the freelancer.');
    }

    public function show(Request $request,FreelanceContract $contract): View
    {
        abort_unless($contract->isParticipant((int)$request->user()->id),404);
        $contract->load(['client:id,name,public_id','freelancer:id,name,public_id',
            'milestones','proposal:id,marketplace_job_id,cover_message,proposed_budget',
            'dispute','consents.actor:id,name']);
        $messages=$contract->messages()->with('author:id,name')->oldest('id')->paginate(10,['*'],'messages');
        $latestSubmissions=[];
        foreach($contract->milestones as $milestone){
            $latestSubmissions[$milestone->id]=$milestone->submissions()->with('review')->latest('id')->first();
        }
        return view('contracts.show',compact('contract','messages','latestSubmissions'));
    }

    public function addMilestone(Request $request,FreelanceContract $contract): RedirectResponse
    {
        $this->clientOnly($request,$contract);
        $data=$request->validate([
            'title'=>['required','string','min:5','max:180'],
            'description'=>['required','string','min:30','max:4000'],
            'amount'=>['required','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'due_on'=>['nullable','date','after_or_equal:today'],
        ]);
        DB::transaction(function()use($request,$contract,$data): void {
            $locked=FreelanceContract::query()->lockForUpdate()->findOrFail($contract->id);
            abort_unless($locked->status==='draft',409,'Milestones are frozen after the agreement is offered.');
            $sequence=(int)$locked->milestones()->max('sequence')+1;
            abort_if($sequence>20,409,'At most twenty milestones are supported.');
            $locked->milestones()->create([...$data,'sequence'=>$sequence,'status'=>'planned']);
            AuditEvent::record('contract.milestone.created',$request->user(),$request);
        });
        return back()->with('status','Milestone added to draft.');
    }

    public function removeMilestone(Request $request,ContractMilestone $milestone): RedirectResponse
    {
        $this->clientOnly($request,$milestone->contract);
        DB::transaction(function()use($request,$milestone): void {
            $contract=FreelanceContract::query()->lockForUpdate()->findOrFail($milestone->freelance_contract_id);
            abort_unless($contract->status==='draft',409);
            $milestone->delete();
            AuditEvent::record('contract.milestone.removed',$request->user(),$request);
        });
        return back()->with('status','Draft milestone removed.');
    }

    public function offer(Request $request,FreelanceContract $contract): RedirectResponse
    {
        $this->clientOnly($request,$contract);
        $request->validate(['confirm_offer'=>['required','accepted']]);

        DB::transaction(function()use($contract,$request): void {
            $locked=FreelanceContract::query()->lockForUpdate()->findOrFail($contract->id);
            abort_unless($locked->status==='draft',409);
            $milestones=$locked->milestones()->orderBy('sequence')->get();
            abort_if($milestones->isEmpty(),409,'Add at least one milestone.');
            $sum=$milestones->sum(fn($m)=>(int)round((float)$m->amount*100));
            $budget=(int)round((float)$locked->agreed_budget*100);
            if($sum!==$budget){
                throw ValidationException::withMessages(['confirm_offer'=>'Milestone totals must equal the agreed budget.']);
            }

            $hash=$locked->termsDigest();
            $locked->update(['status'=>'offered','terms_sha256'=>$hash,'offered_at'=>now()]);
            ContractConsent::query()->create([
                'freelance_contract_id'=>$locked->id,'user_id'=>$request->user()->id,
                'action'=>'offer_confirmed','terms_sha256'=>$hash,'created_at'=>now(),
            ]);
            AccountNotification::deliver((int)$locked->freelancer_id,'contract.offered',
                'A contract offer requires your review',
                'A client offered an agreement with detailed milestones and terms.',
                '/contracts/'.$locked->id);
            AuditEvent::record('contract.offered',$request->user(),$request);
        });

        return back()->with('status','Agreement offered to the freelancer for explicit acceptance.');
    }

    public function respond(Request $request,FreelanceContract $contract): RedirectResponse
    {
        $this->freelancerOnly($request,$contract);
        $data=$request->validate([
            'decision'=>['required',Rule::in(['accepted','declined'])],
            'confirm_terms'=>['required_if:decision,accepted','nullable','accepted'],
        ]);

        DB::transaction(function()use($contract,$request,$data): void {
            $locked=FreelanceContract::query()->lockForUpdate()->findOrFail($contract->id);
            abort_unless($locked->status==='offered',409);
            abort_unless($locked->terms_sha256 && hash_equals($locked->terms_sha256,$locked->termsDigest()),
                409,'Offer contents have changed since the client confirmed them.');
            $action=$data['decision']==='accepted'?'accepted':'declined';
            $locked->update([
                'status'=>$action==='accepted'?'active':'declined',
                'accepted_at'=>$action==='accepted'?now():null,
            ]);
            ContractConsent::query()->create([
                'freelance_contract_id'=>$locked->id,'user_id'=>$request->user()->id,
                'action'=>$action,'terms_sha256'=>$locked->terms_sha256,'created_at'=>now(),
            ]);
            AccountNotification::deliver((int)$locked->client_id,
                'contract.'.$action,'Contract offer '.$action,
                'The freelancer has '.($action==='accepted'?'accepted':'declined').' your original offer.',
                '/contracts/'.$locked->id);
            AuditEvent::record('contract.'.$action,$request->user(),$request);
        });

        return back()->with('status','Offer decision recorded in the audit history.');
    }

    public function cancel(Request $request,FreelanceContract $contract): RedirectResponse
    {
        $this->clientOnly($request,$contract);
        DB::transaction(function()use($request,$contract){
            $locked=FreelanceContract::query()->lockForUpdate()->findOrFail($contract->id);
            abort_unless(in_array($locked->status,['draft','offered'],true),409);
            $locked->update(['status'=>'cancelled','cancelled_at'=>now()]);
            if($contract->status==='offered'){
                AccountNotification::deliver((int)$locked->freelancer_id,'contract.cancelled',
                    'Contract offer withdrawn','The client withdrew an unaccepted agreement.',
                    '/contracts/'.$locked->id);
            }
            AuditEvent::record('contract.cancelled',$request->user(),$request);
        });
        return back()->with('status','Unaccepted contract withdrawn.');
    }

    public function acknowledgeCompletion(Request $request,FreelanceContract $contract): RedirectResponse
    {
        $this->freelancerOnly($request,$contract);
        $request->validate(['confirm_completion'=>['required','accepted']]);
        DB::transaction(function()use($request,$contract){
            $locked=FreelanceContract::query()->lockForUpdate()->findOrFail($contract->id);
            abort_unless($locked->status==='active' && $locked->freelancer_completed_at===null,409);
            abort_if($locked->milestones()->where('status','!=','accepted')->exists(),409);
            $locked->update(['freelancer_completed_at'=>now()]);
            AccountNotification::deliver((int)$locked->client_id,'contract.completion.acknowledged',
                'Freelancer confirmed milestone delivery',
                'All milestones are accepted and the freelancer has acknowledged completion.',
                '/contracts/'.$locked->id);
            AuditEvent::record('contract.completion.acknowledged',$request->user(),$request);
        });
        return back()->with('status','Completion acknowledgement recorded.');
    }

    public function complete(Request $request,FreelanceContract $contract): RedirectResponse
    {
        $this->clientOnly($request,$contract);
        $request->validate(['confirm_completion'=>['required','accepted']]);
        DB::transaction(function()use($request,$contract){
            $locked=FreelanceContract::query()->lockForUpdate()->findOrFail($contract->id);
            abort_unless($locked->status==='active' && $locked->freelancer_completed_at!==null,409);
            abort_if($locked->milestones()->where('status','!=','accepted')->exists(),409);
            $locked->update(['status'=>'completed','completed_at'=>now()]);
            AccountNotification::deliver((int)$locked->freelancer_id,'contract.completed',
                'Contract delivery completed',
                'The client confirmed completion of the accepted milestones.',
                '/contracts/'.$locked->id);
            AuditEvent::record('contract.completed',$request->user(),$request);
        });
        return back()->with('status','Both parties recorded completion. No payment was created.');
    }

    private function clientOnly(Request $request,FreelanceContract $contract): void
    {
        abort_unless((int)$contract->client_id===(int)$request->user()->id,404);
        abort_unless(in_array($request->user()->role,['customer','subscriber','super_admin'],true),403);
    }

    private function freelancerOnly(Request $request,FreelanceContract $contract): void
    {
        abort_unless((int)$contract->freelancer_id===(int)$request->user()->id,404);
        abort_unless($request->user()->role==='freelancer',403);
    }
}
