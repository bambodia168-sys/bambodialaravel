<?php

namespace App\Http\Controllers;

use App\Models\Policy;
use App\Models\PolicyAcknowledgement;
use App\Models\PolicyPublication;
use App\Models\PolicyUpdateNotice;
use App\Support\PolicyContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PolicyCenterController extends Controller
{
    public function index(Request $request): View
    {
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 120);
        $policies = Policy::query()
            ->where('status', 'published')
            ->when($search !== '', fn ($query) => $query->where('title', 'like', '%'.$search.'%'))
            ->orderBy('title')->paginate(15)->withQueryString();

        $notices = PolicyUpdateNotice::query()
            ->with('policy:id,title,public_code,slug,status')
            ->where('show_banner', true)
            ->whereHas('policy', fn ($query) => $query->where('status', 'published'))
            ->whereRaw('policy_publication_id = (SELECT MAX(id) FROM policy_publications WHERE policy_id = policy_update_notices.policy_id)')
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->latest()->limit(5)->get();

        return view('policies.index', compact('policies', 'search', 'notices'));
    }

    // Historic nine-digit query-style URLs continue working.
    public function show(Request $request): View|RedirectResponse
    {
        $raw = (string) $request->server('QUERY_STRING', '');
        abort_unless(preg_match('/^=(\d{9})-([a-z0-9-]+)$/D', $raw, $match) === 1, 404);
        $policy = Policy::query()->where('status', 'published')
            ->where('public_id', $match[1])->firstOrFail();
        if ($policy->slug !== $match[2]) {
            return redirect($policy->publicUrl(), 301);
        }
        return $this->showPolicy($request, $policy);
    }

    public function showByReference(Request $request, string $reference, string $slug): View|RedirectResponse
    {
        $policy = Policy::query()->where('status', 'published')
            ->where('public_code', $reference)->firstOrFail();
        if ($policy->slug !== $slug) {
            return redirect($policy->publicUrl(), 301);
        }
        return $this->showPolicy($request, $policy);
    }

    private function showPolicy(Request $request, Policy $policy): View
    {
        $otherPolicies = Policy::query()->where('status', 'published')
            ->orderBy('title')->get(['id', 'public_code', 'public_id', 'title', 'slug']);
        $publication = PolicyPublication::query()->where('policy_id', $policy->id)
            ->orderByDesc('revision')->first();
        $acknowledged = $publication && $request->user()
            ? PolicyAcknowledgement::query()->where('policy_publication_id', $publication->id)
                ->where('user_id', $request->user()->id)->exists()
            : false;
        $policyHtml = PolicyContent::display(
            $publication?->body_snapshot ?? $policy->body,
            $publication?->body_format ?? $policy->body_format ?? 'markdown'
        );
        return view('policies.show', compact(
            'policy', 'otherPolicies', 'publication', 'acknowledged', 'policyHtml'
        ));
    }
}
