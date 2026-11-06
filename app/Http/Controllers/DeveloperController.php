<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\AuditEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class DeveloperController extends Controller
{
    public function index(Request $request): Response
    {
        $status = (string) $request->query('status', 'all');
        abort_unless(in_array($status, ['all', 'active', 'expiring', 'expired', 'revoked'], true), 404);

        $ownerTokens = ApiToken::query()->where('user_id', $request->user()->id);
        $now = now();
        $soon = $now->copy()->addDays(7);

        // Mutually exclusive revoked/expired counts; expiring is a subset of active.
        $tokenStats = [
            'active' => (clone $ownerTokens)->whereNull('revoked_at')
                ->where('expires_at', '>', $now)->count(),
            'expiring' => (clone $ownerTokens)->whereNull('revoked_at')
                ->where('expires_at', '>', $now)->where('expires_at', '<=', $soon)->count(),
            'expired' => (clone $ownerTokens)->whereNull('revoked_at')
                ->where('expires_at', '<=', $now)->count(),
            'revoked' => (clone $ownerTokens)->whereNotNull('revoked_at')->count(),
        ];

        $tokenStats['unused'] = (clone $ownerTokens)->whereNull('revoked_at')
            ->where('expires_at','>',$now)->whereNull('last_used_at')->count();
        // Only the authenticated owner's event type and timestamp are shown.
        $recentActivity = AuditEvent::query()
            ->where('actor_id',$request->user()->id)
            ->where('event','like','developer.%')
            ->latest('created_at')->limit(8)->get(['id','event','created_at']);

        $tokens = (clone $ownerTokens)
            ->when($status === 'active', fn ($q) => $q->whereNull('revoked_at')->where('expires_at', '>', $now))
            ->when($status === 'expiring', fn ($q) => $q->whereNull('revoked_at')
                ->where('expires_at', '>', $now)->where('expires_at', '<=', $soon))
            ->when($status === 'expired', fn ($q) => $q->whereNull('revoked_at')->where('expires_at', '<=', $now))
            ->when($status === 'revoked', fn ($q) => $q->whereNotNull('revoked_at'))
            ->latest()->paginate(10)->withQueryString();

        return response()->view('developer.index', [
            'tokens' => $tokens,
            'tokenStats' => $tokenStats,
            'recentActivity' => $recentActivity,
            'status' => $status,
            'scopes' => ApiToken::SCOPES,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): RedirectResponse
    {
        $values = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:80'],
            'days' => ['required', 'integer', Rule::in([7, 30, 90])],
            'scopes' => ['required', 'array', 'min:1', 'max:2'],
            'scopes.*' => ['required', 'string', 'distinct', Rule::in(ApiToken::SCOPES)],
            'password' => ['required', 'current_password'],
        ]);

        [$token, $secret] = ApiToken::issue(
            $request->user(),
            $values['name'],
            array_values($values['scopes']),
            (int) $values['days']
        );

        AuditEvent::record('developer.token.created', $request->user(), $request);
        // Only the token hash is stored in the database; the secret is flashed once.
        $request->session()->flash('issued_token', $secret);

        return redirect()->route('developer.index')
            ->with('status', 'Access token issued. Copy it now: it cannot be retrieved again.');
    }

    public function revoke(Request $request, ApiToken $token): RedirectResponse
    {
        abort_unless((int) $token->user_id === (int) $request->user()->id, 404);
        $request->validate(['password' => ['required', 'current_password']]);

        if ($token->revoked_at === null) {
            $token->update(['revoked_at' => now()]);
            AuditEvent::record('developer.token.revoked', $request->user(), $request);
        }

        return back()->with('status', 'Token revoked.');
    }

    public function documentation(): Response
    {
        return response()->view('developer.docs')->header('Cache-Control', 'private, no-store');
    }
}
