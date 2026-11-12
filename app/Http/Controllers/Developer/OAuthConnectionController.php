<?php

namespace App\Http\Controllers\Developer;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

final class OAuthConnectionController extends Controller
{
    public function index(Request $request): Response
    {
        $tokens = Passport::token()->newQuery()
            ->where('user_id', $request->user()->id)
            ->whereJsonContains('scopes', 'mcp:use')
            ->with('client')
            ->orderByDesc('created_at')
            ->paginate(10)
            ->withQueryString();

        return response()->view('developer.oauth-connections', compact('tokens'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, string $token): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        DB::transaction(function () use ($request, $token): void {
            $record = Passport::token()->newQuery()
                ->where('user_id', $request->user()->id)
                ->whereJsonContains('scopes', 'mcp:use')
                ->lockForUpdate()
                ->findOrFail($token);

            $record->revoke();
            // A revoked access token must not retain a usable refresh credential.
            $record->refreshToken?->revoke();
        });

        return back()->with('status', 'OAuth connection access revoked.');
    }
}
