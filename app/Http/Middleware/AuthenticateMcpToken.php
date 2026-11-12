<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Models\User;
use App\Support\Mcp\McpAccessGrant;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Preserve existing developer tokens while accepting Passport OAuth PKCE tokens.
 * Session cookies alone never grant MCP access.
 */
final class AuthenticateMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = $request->bearerToken() ?? '';
        $challenge = 'Bearer resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'", scope="mcp:use"';
        $reject = static fn (int $status, string $message): JsonResponse =>
            (new JsonResponse(['message' => $message], $status))
                ->header('WWW-Authenticate', $challenge)
                ->header('Cache-Control', 'no-store');

        if ($secret === '') {
            return $reject(401, 'A valid bearer token is required.');
        }

        if (str_starts_with($secret, 'bmb_')) {
            $grant = ApiToken::authenticate($secret);
            if (! $grant) {
                return $reject(401, 'The developer token is invalid, expired or revoked.');
            }
            $owner = $grant->owner;
            if (! $grant->allows('mcp.read')) {
                return $reject(403, 'The developer token does not permit MCP access.');
            }
        } else {
            // Passport validates JWT signature, expiry and token revocation.
            $owner = Auth::guard('api')->user();
            if (! $owner instanceof User || ! $owner->tokenCan('mcp:use')) {
                return $reject(401, 'Invalid or insufficient OAuth bearer token.');
            }

            // Passport has already verified the signed JWT and checked
            // revocation. Now require the same signed token to name /mcp as its
            // SECOND audience (after Passport's client ID).
            $verified = $owner->token();
            if (! $verified instanceof \Laravel\Passport\AccessToken
                || ! \App\Support\Mcp\McpResource::hasAudience(
                    $secret,
                    (string) ($verified->oauth_access_token_id ?? ''),
                    (string) ($verified->oauth_client_id ?? '')
                )) {
                return $reject(401, 'OAuth token was not issued for this MCP resource.');
            }

            $grant = new McpAccessGrant($owner->tokenCan('mcp.write'));
        }

        if (! $owner instanceof User || $owner->role !== 'super_admin'
            || $owner->account_status !== 'active') {
            return $reject(403, 'MCP access requires an active super administrator.');
        }

        $request->attributes->set('bambodia_api_owner', $owner);
        $request->attributes->set('bambodia_api_token', $grant);

        if ($grant instanceof ApiToken) {
            $grant->forceFill(['last_used_at' => now()])->save();
        }

        return $next($request);
    }
}
