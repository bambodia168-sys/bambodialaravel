<?php

namespace App\Http\Controllers;

use App\Models\Policy;
use App\Models\PolicyPublication;
use App\Models\PolicyAcknowledgement;
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
            ->orderBy('title')->paginate(10)->withQueryString();

        return view('policies.index', compact('policies', 'search'));
    }

    public function show(Request $request): View
    {
        $raw = (string) $request->server('QUERY_STRING', '');
        abort_unless(preg_match('/^=(\d{9})-([a-z0-9-]+)$/', $raw, $match) === 1, 404);

        $policy = Policy::query()
            ->where('status', 'published')
            ->where('public_id', $match[1])
            ->where('slug', $match[2])
            ->firstOrFail();

        $otherPolicies = Policy::query()->where('status', 'published')->orderBy('title')->get(['id', 'public_id', 'title', 'slug']);

        $publication=PolicyPublication::query()->where('policy_id',$policy->id)
            ->orderByDesc('revision')->first();
        $acknowledged=$publication && $request->user()
            ? PolicyAcknowledgement::query()->where('policy_publication_id',$publication->id)
                ->where('user_id',$request->user()->id)->exists()
            : false;

        return view('policies.show', compact('policy', 'otherPolicies','publication','acknowledged'));
    }
}
