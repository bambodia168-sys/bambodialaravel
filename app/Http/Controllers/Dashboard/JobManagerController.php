<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\AccountNotification;
use App\Models\MarketplaceJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class JobManagerController extends Controller
{
    public function index(Request $request): View
    {
        $status=(string)$request->query('status','pending');
        abort_unless(in_array($status,['all','pending','published','rejected','closed'],true),404);
        $jobs=MarketplaceJob::query()->with('client:id,name,public_id')
            ->when($status !== 'all',fn($q)=>$q->where('status',$status))
            ->latest()->paginate(10)->withQueryString();
        return view('dashboard.jobs', compact('jobs','status'));
    }
    public function decide(Request $request, MarketplaceJob $job): RedirectResponse
    {
        $data=$request->validate(['decision'=>['required',Rule::in(['published','rejected'])]]);
        DB::transaction(function()use($job,$data,$request){
            $locked=MarketplaceJob::query()->lockForUpdate()->findOrFail($job->id);
            abort_unless($locked->status==='pending',409);
            $locked->update(['status'=>$data['decision'],'reviewed_by'=>$request->user()->id,'reviewed_at'=>now()]);
            AccountNotification::deliver(
                (int)$locked->client_id,'job.reviewed','Job review completed',
                'Your submitted job was '.($data['decision']==='published'?'published':'rejected').'.',
                '/my-jobs'
            );
            AuditEvent::record('job.review.'.$data['decision'],$request->user(),$request);
        });
        return back()->with('status','Job review recorded.');
    }
}
