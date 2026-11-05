<?php

namespace App\Http\Controllers;

use App\Models\AccountNotification;
use App\Models\AuditEvent;
use App\Models\ContractMilestone;
use App\Models\MilestoneReview;
use App\Models\MilestoneSubmission;
use App\Support\ContractArtifact;
use App\Support\ProductVault;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContractMilestoneController extends Controller
{
    public function deliver(Request $request,ContractMilestone $milestone): RedirectResponse
    {
        $contract=$milestone->contract;
        abort_unless((int)$contract->freelancer_id===(int)$request->user()->id,404);
        abort_unless($request->user()->role==='freelancer',403);
        $data=$request->validate([
            'delivery_notes'=>['required','string','min:40','max:12000'],
            'evidence_url'=>['nullable','url','starts_with:https://','max:500'],
            'artifact'=>['nullable','file','max:'.ProductVault::MAX_UPLOAD_KB],
        ]);

        $file=$request->file('artifact');
        $metadata=[];
        if($file){
            $metadata=ContractArtifact::persist($file,(int)$contract->id,(int)$milestone->id);
        }

        try {
        DB::transaction(function()use($milestone,$data,$request,$metadata){
            $locked=ContractMilestone::query()->lockForUpdate()->findOrFail($milestone->id);
            $contract=$locked->contract()->lockForUpdate()->firstOrFail();
            abort_unless($contract->status==='active',409,'Milestones may only be submitted under an active agreement.');
            abort_unless(in_array($locked->status,['planned','revision_requested'],true),409);
            abort_if($contract->milestones()->where('sequence','<',$locked->sequence)
                ->where('status','!=','accepted')->exists(),409,
                'Previous milestones must be accepted first.');

            MilestoneSubmission::query()->create([
                'contract_milestone_id'=>$locked->id,'submitted_by'=>$request->user()->id,
                'delivery_notes'=>$data['delivery_notes'],'evidence_url'=>$data['evidence_url']??null,
                ...$metadata,'created_at'=>now(),
            ]);
            $locked->update(['status'=>'submitted']);
            AccountNotification::deliver((int)$contract->client_id,'contract.milestone.submitted',
                'A contract milestone is ready for review',
                'The freelancer submitted the deliverables for milestone '.$locked->sequence.'.',
                '/contracts/'.$contract->public_id);
            AuditEvent::record('contract.milestone.submitted',$request->user(),$request);
        });
        } catch (\Throwable $error){
            if(!empty($metadata['storage_path'])){
                Storage::disk('local')->delete($metadata['storage_path']);
            }
            throw $error;
        }

        return back()->with('status','Deliverable submitted privately for client review.');
    }

    public function review(Request $request,ContractMilestone $milestone): RedirectResponse
    {
        $contract=$milestone->contract;
        abort_unless((int)$contract->client_id===(int)$request->user()->id,404);
        abort_unless(in_array($request->user()->role,['customer','subscriber','super_admin'],true),403);
        $data=$request->validate([
            'decision'=>['required',Rule::in(['accepted','revision_requested'])],
            'feedback'=>['required','string','min:15','max:4000'],
        ]);
        DB::transaction(function()use($milestone,$request,$data){
            $locked=ContractMilestone::query()->lockForUpdate()->findOrFail($milestone->id);
            $contract=$locked->contract()->lockForUpdate()->firstOrFail();
            abort_unless($contract->status==='active' && $locked->status==='submitted',409);
            $submission=$locked->submissions()->latest('id')->firstOrFail();
            if($submission->storage_path!==null){
                abort_unless(ContractArtifact::verifiedPath($submission)!==null,409,
                    'Uploaded evidence is missing or failed its integrity check.');
            }
            abort_if(MilestoneReview::query()->where('milestone_submission_id',$submission->id)->exists(),
                409,'This delivery already has a review.');
            MilestoneReview::query()->create([
                'milestone_submission_id'=>$submission->id,
                'reviewed_by'=>$request->user()->id,
                'decision'=>$data['decision'],
                'feedback'=>$data['feedback'],
                'created_at'=>now(),
            ]);
            $locked->update([
                'status'=>$data['decision'],
                'accepted_at'=>$data['decision']==='accepted'?now():null,
            ]);
            AccountNotification::deliver((int)$contract->freelancer_id,'contract.milestone.reviewed',
                'Milestone review recorded',
                'The client '.($data['decision']==='accepted'?'accepted':'requested revisions for').
                    ' milestone '.$locked->sequence.'.',
                '/contracts/'.$contract->public_id);
            AuditEvent::record('contract.milestone.'.$data['decision'],$request->user(),$request);
        });

        return back()->with('status','Milestone review recorded and the freelancer notified.');
    }
}
