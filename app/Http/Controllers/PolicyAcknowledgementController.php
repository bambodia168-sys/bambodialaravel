<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\PolicyAcknowledgement;
use App\Models\PolicyPublication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PolicyAcknowledgementController extends Controller
{
    public function index(Request $request): View
    {
        $receipts=PolicyAcknowledgement::query()
            ->where('user_id',$request->user()->id)
            ->with('publication:id,policy_id,revision,title,sha256,created_at')
            ->latest('id')->paginate(10);
        return view('account.policy-receipts',compact('receipts'));
    }

    public function acknowledge(Request $request,PolicyPublication $publication): RedirectResponse
    {
        $request->validate(['confirm_read'=>['required','accepted']]);
        DB::transaction(function()use($request,$publication){
            $policy=$publication->policy()->lockForUpdate()->firstOrFail();
            abort_unless($policy->status==='published',409);
            $latest=PolicyPublication::query()->where('policy_id',$policy->id)
                ->orderByDesc('revision')->firstOrFail();
            abort_unless($latest->id===$publication->id,409,'Please acknowledge the current policy publication.');
            abort_unless(hash_equals($publication->sha256,PolicyPublication::digestFor($policy)),409,
                'The currently displayed policy text does not match the recorded publication.');
            abort_if(PolicyAcknowledgement::query()
                ->where('policy_publication_id',$publication->id)
                ->where('user_id',$request->user()->id)->exists(),409,
                'This policy revision has already been acknowledged.');

            PolicyAcknowledgement::query()->create([
                'policy_publication_id'=>$publication->id,
                'user_id'=>$request->user()->id,
                'sha256'=>$publication->sha256,
                'record_type'=>'read_acknowledgement',
                'created_at'=>now(),
            ]);
            AuditEvent::record('policy.read.acknowledged',$request->user(),$request);
        });

        return redirect()->route('account.policy-receipts')
            ->with('status','Your optional acknowledgment of this exact policy revision was recorded.');
    }
}
