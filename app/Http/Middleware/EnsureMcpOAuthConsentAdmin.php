<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Mcp\McpResource;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validate final Passport consent AFTER the "web" middleware started the
 * browser session. Global middleware runs before StartSession and cannot
 * reliably resolve the admin's session on an actual HTTP POST.
 */
final class EnsureMcpOAuthConsentAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->is('oauth/authorize')) {
            abort(404);
        }

        if (! config('mcp.production_enabled', false)
            || McpResource::issuer() !== 'https://bambodia.com') {
            return response()->json(['error' => 'temporarily_unavailable'], 503)
                ->header('Cache-Control', 'no-store');
        }

        $owner = $request->user('web');
        // A session may retain a user model loaded before an administrator
        // was demoted or suspended. Never trust a cached role at consent:
        // re-read the authoritative database state on every approval.
        $stillAllowed = $owner instanceof User
            && $owner->exists
            && User::query()->whereKey($owner->getKey())
                ->where('role', 'super_admin')
                ->where('account_status', 'active')
                ->exists();
        if (! $stillAllowed) {
            return response()->json([
                'message' => 'Administrator authorization is no longer allowed.',
            ], 403)->header('Cache-Control', 'no-store');
        }

        return $next($request);
    }
}
