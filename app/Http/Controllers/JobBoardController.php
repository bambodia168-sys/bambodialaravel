<?php

namespace App\Http\Controllers;

use App\Support\BuilderContent;
use App\Support\BuilderGallery;
use App\Support\BuilderSubmissionTerms;
use App\Support\BuilderFeaturedMedia;
use App\Support\BuilderGallery;
use App\Models\AuditEvent;
use App\Models\AccountNotification;
use App\Models\FreelancerResume;
use App\Models\JobProposal;
use App\Models\MarketplaceJob;
use App\Models\SavedJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class JobBoardController extends Controller
{
    public function index(Request $request): View
    {
        $search = mb_substr(trim((string)$request->query('q','')),0,120);
        $category=(string)$request->query('category','');
        abort_unless($category==='' || in_array($category,['development','design','writing','marketing','business','other'],true),404);
        $sort=(string)$request->query('sort','recent');
        abort_unless(in_array($sort,['recent','budget_low','budget_high'],true),404);
        $jobs = MarketplaceJob::query()->with(['client:id,name,public_id','featuredAsset'])->withCount('proposals')
            ->where('status','published')
            ->when($category!=='',fn($q)=>$q->where('category',$category))
            ->when($search !== '', fn($q)=>$q->where(function($query)use($search){
                $query->where('title','like','%'.$search.'%')->orWhere('description','like','%'.$search.'%');
            }))
            ->when($sort==='budget_low'||$sort==='budget_high',fn($q)=>$q
                ->orderByRaw('CASE WHEN budget IS NULL THEN 1 ELSE 0 END')
                ->orderBy('budget',$sort==='budget_low'?'asc':'desc'))
            ->when($sort==='recent',fn($q)=>$q->orderByDesc('created_at'))
            ->orderByDesc('id')->paginate(10)->withQueryString();
        $savedJobIds=$request->user()
            ? SavedJob::query()->where('user_id',$request->user()->id)
                ->whereIn('marketplace_job_id',$jobs->pluck('id'))
                ->pluck('marketplace_job_id')->map(fn($id)=>(int)$id)->all()
            : [];
        return view('jobs.index',compact('jobs','search','category','sort','savedJobIds'));
    }

    public function show(Request $request, string $job): View
    {
        abort_unless(preg_match('/^(\d{9})-([a-z0-9-]+)$/D', $job, $match)===1,404);
        $listing = MarketplaceJob::query()->with('client:id,name,public_id')
            ->where('public_id',$match[1])->where('slug',$match[2])->where('status','published')->firstOrFail();
        $applied = $request->user()
            ? JobProposal::query()->where('marketplace_job_id',$listing->id)->where('freelancer_id',$request->user()->id)->exists()
            : false;
        $isSaved=$request->user()
            ? SavedJob::query()->where('user_id',$request->user()->id)
                ->where('marketplace_job_id',$listing->id)->exists()
            : false;
        $screenshots = BuilderGallery::approvedFor($listing->gallery_media_ids, (int)$listing->client_id);
        return view('jobs.show',['job'=>$listing,'applied'=>$applied,'isSaved'=>$isSaved,'screenshots'=>$screenshots]);
    }

    public function mine(Request $request): View
    {
        abort_unless(in_array($request->user()->role,['customer','subscriber','super_admin'],true),403);
        $jobs=MarketplaceJob::query()->where('client_id',$request->user()->id)
            ->withCount('proposals')->latest()->paginate(10);
        return view('jobs.mine', compact('jobs'));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(in_array($request->user()->role,['customer','subscriber','super_admin'],true),403);
        $termsHash = BuilderSubmissionTerms::requireAcceptance($request);
        BuilderContent::prepare($request);
        $data=$request->validate([
            'title'=>['required','string','min:8','max:180'],
            'description'=>['required','string','min:70','max:10000'],
            ...BuilderFeaturedMedia::rules(),
            ...BuilderGallery::rules(),
            'description_html'=>['nullable','string','max:60000'],
            'category'=>['required',Rule::in(['development','design','writing','marketing','business','other'])],
            'budget'=>['nullable','numeric','min:0','max:9999999999.99'],
        ]);
        BuilderFeaturedMedia::check($data,(int)$request->user()->id);
        BuilderGallery::check($data, $request);
        $job = MarketplaceJob::query()->create([...$data, 'client_id'=>$request->user()->id,'currency'=>'USD','status'=>'pending']);
        BuilderSubmissionTerms::record($termsHash, (int)$request->user()->id, 'job', (int)$job->id);
        AuditEvent::record('job.submitted',$request->user(),$request);
        if ($request->boolean('builder_mode')) {
            return redirect('/builder?='.$job->public_id)->with('status', 'Job submitted for administrator review.');
        }
        return back()->with('status','Job submitted for administrator review.');
    }

    public function close(Request $request, MarketplaceJob $job): RedirectResponse
    {
        abort_unless((int)$job->client_id === (int)$request->user()->id,404);
        DB::transaction(function()use($job,$request){
            $locked=MarketplaceJob::query()->lockForUpdate()->findOrFail($job->id);
            abort_unless(in_array($locked->status,['published','pending'],true),409);
            $locked->update(['status'=>'closed','closed_at'=>now()]);
            AuditEvent::record('job.closed',$request->user(),$request);
        });
        return back()->with('status','Job closed; new proposals are disabled.');
    }

    public function proposals(Request $request, MarketplaceJob $job): View
    {
        abort_unless((int)$job->client_id === (int)$request->user()->id,404);
        $proposals=JobProposal::query()->where('marketplace_job_id',$job->id)
            ->with('freelancer.resume')->latest()->paginate(10);
        return view('jobs.proposals',compact('job','proposals'));
    }

    public function applied(Request $request): View
    {
        abort_unless($request->user()->role === 'freelancer',403);
        $proposals=JobProposal::query()->where('freelancer_id',$request->user()->id)
            ->with('job:id,public_id,slug,title,status')->latest()->paginate(10);
        return view('jobs.applied',compact('proposals'));
    }

    public function apply(Request $request, MarketplaceJob $job): RedirectResponse
    {
        abort_unless($request->user()->role === 'freelancer',403);
        $values=$request->validate([
            'cover_message'=>['required','string','min:50','max:5000'],
            'proposed_budget'=>['nullable','numeric','min:0','max:9999999999.99'],
        ]);
        $resume=FreelancerResume::query()->where('user_id',$request->user()->id)->first();
        abort_unless($resume && $resume->is_visible,403,'Publish your professional resume before submitting proposals.');

        DB::transaction(function()use($job,$request,$values){
            $locked=MarketplaceJob::query()->lockForUpdate()->findOrFail($job->id);
            abort_unless($locked->status==='published' && (int)$locked->client_id !== (int)$request->user()->id,409);
            abort_if(JobProposal::query()->where('marketplace_job_id',$locked->id)
                ->where('freelancer_id',$request->user()->id)->exists(),409,'Already applied to this job.');
            JobProposal::query()->create([...$values,'marketplace_job_id'=>$locked->id,
                'freelancer_id'=>$request->user()->id,'status'=>'submitted']);
            AccountNotification::deliver(
                (int)$locked->client_id,'job.proposal.received','New job application',
                'A freelancer submitted a proposal for your job.','/my-jobs/'.$locked->id.'/proposals'
            );
            AuditEvent::record('job.proposal.submitted',$request->user(),$request);
        });
        return back()->with('status','Your proposal was submitted to the job owner.');
    }

    public function decide(Request $request, JobProposal $proposal): RedirectResponse
    {
        $data=$request->validate(['decision'=>['required',Rule::in(['shortlisted','declined'])]]);
        DB::transaction(function()use($proposal,$request,$data){
            $locked=JobProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $job=MarketplaceJob::query()->findOrFail($locked->marketplace_job_id);
            abort_unless((int)$job->client_id === (int)$request->user()->id,404);
            abort_unless($locked->status==='submitted',409);
            $locked->update(['status'=>$data['decision'],'responded_at'=>now()]);
            AccountNotification::deliver(
                (int)$locked->freelancer_id,'job.proposal.decision','Job application reviewed',
                'The client has '.($data['decision']==='shortlisted'?'shortlisted':'declined').' your proposal.',
                '/my-proposals'
            );
            AuditEvent::record('job.proposal.reviewed',$request->user(),$request);
        });
        return back()->with('status','Proposal decision recorded. No contract or payment was created.');
    }
}
