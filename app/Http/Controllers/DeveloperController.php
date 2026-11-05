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
        $tokens = ApiToken::query()
            ->where('user_id', $request->user()->id)
            ->latest()->paginate(10);

        return response()->view('developer.index', [
            'tokens' => $tokens,
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
