<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\MarketplaceJob;
use App\Models\SavedJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SavedJobController extends Controller
{
    public function index(Request $request): View
    {
        $saved=SavedJob::query()->where('user_id',$request->user()->id)
            ->with(['job'=>fn($q)=>$q->where('status','published')
                ->with('client:id,name,public_id')])
            ->latest()->paginate(10);
        return view('account.saved-jobs',compact('saved'));
    }

    public function store(Request $request,MarketplaceJob $job): RedirectResponse
    {
        abort_unless($job->status==='published',404);
        $inserted=DB::table('saved_jobs')->insertOrIgnore([
            'user_id'=>$request->user()->id,
            'marketplace_job_id'=>$job->id,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        if($inserted){
            AuditEvent::record('job.saved',$request->user(),$request);
        }
        return back()->with('status','Opportunity saved to your private list.');
    }

    public function destroy(Request $request,MarketplaceJob $job): RedirectResponse
    {
        $removed=SavedJob::query()->where('user_id',$request->user()->id)
            ->where('marketplace_job_id',$job->id)->delete();
        if($removed){
            AuditEvent::record('job.unsaved',$request->user(),$request);
        }
        return back()->with('status','Opportunity removed from your saved list.');
    }
}
