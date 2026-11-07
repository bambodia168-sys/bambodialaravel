<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Policy;
use App\Support\LegalPolicyDrafts;
use App\Models\PolicyPublication;
use Illuminate\Support\Facades\DB;
use App\Models\AuditEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PolicyManagerController extends Controller
{
    public function index(): View
    {
        return view('dashboard.policies', ['policies' => Policy::query()->latest()->paginate(10)]);
    }

    public function seedLegalDrafts(Request $request): RedirectResponse
    {
        $created = DB::transaction(function (): int {
            $created = 0;
            foreach (LegalPolicyDrafts::all() as $template) {
                // Never replace an existing or already published policy.
                if (Policy::query()->where('title', $template['title'])->exists()) {
                    continue;
                }
                Policy::query()->create([...$template, 'status' => 'draft']);
                $created++;
            }
            return $created;
        });

        AuditEvent::record('policy.legal_drafts_created', $request->user(), $request);

        return back()->with('status', $created.' new legal policy drafts created. Review each document and publish individually after legal approval.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:4', 'max:180', 'unique:policies,title'],
            'body' => ['required', 'string', 'min:50', 'max:100000'],
            'category' => ['required', Rule::in(['general', 'privacy', 'cookies', 'gdpr', 'terms', 'commerce'])],
            'jurisdiction' => ['nullable', 'string', 'max:120'],
        ]);
        Policy::query()->create([...$data, 'status' => 'draft']);

        return back()->with('status', 'Policy saved as a draft for administrator review.');
    }

    public function update(Request $request, Policy $policy): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:4', 'max:180', Rule::unique('policies', 'title')->ignore($policy->id)],
            'body' => ['required', 'string', 'min:50', 'max:100000'],
            'category' => ['required', Rule::in(['general', 'privacy', 'cookies', 'gdpr', 'terms', 'commerce'])],
            'jurisdiction' => ['nullable', 'string', 'max:120'],
            'status' => ['required', Rule::in(['draft', 'published'])],
            'confirm_legal_review' => ['sometimes', 'accepted'],
        ]);

        if ($data['status'] === 'published' && ! $request->boolean('confirm_legal_review')) {
            return back()->withErrors(['status' => 'Confirm an administrator has reviewed the legal text before publication.']);
        }
        unset($data['confirm_legal_review']);
        if ($data['status'] === 'published') {
            $data['reviewed_at'] = now();
            $data['reviewed_by'] = $request->user()->id;
        }
        DB::transaction(function () use ($policy, $data, $request): void {
            $locked=Policy::query()->lockForUpdate()->findOrFail($policy->id);
            $locked->update($data);
            if ($data['status'] === 'published') {
                $revision=(int) PolicyPublication::query()
                    ->where('policy_id',$locked->id)->max('revision')+1;
                PolicyPublication::query()->create([
                    'policy_id'=>$locked->id,
                    'revision'=>$revision,
                    'title'=>$locked->title,
                    'category'=>$locked->category,
                    'jurisdiction'=>$locked->jurisdiction,
                    'body_snapshot'=>$locked->body,
                    'sha256'=>PolicyPublication::digestFor($locked),
                    'published_by'=>$request->user()->id,
                    'created_at'=>now(),
                ]);
                AuditEvent::record('policy.published', $request->user(), $request);
            }
        });

        return back()->with('status', 'Policy saved.');
    }
}
