<?php

namespace App\Http\Middleware;

use App\Support\Mcp\McpResource;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce RFC 8707 on the two Passport endpoints before issuing a code/token.
 *
 * The Passport provider owns these routes, so a path-scoped global guard is
 * safer than registering duplicate /oauth/authorize or /oauth/token routes.
 * All unrelated storefront, developer and API endpoints pass through unchanged.
 */
final class RequireMcpOAuthResource
{
    public function handle(Request $request, Closure $next): Response
    {
        $authorization = $request->isMethod('GET') && $request->is('oauth/authorize');
        $approval = $request->isMethod('POST') && $request->is('oauth/authorize');
        $exchange = $request->isMethod('POST') && $request->is('oauth/token');

        if (($authorization || $approval || $exchange) &&
            (! config('mcp.production_enabled', false)
                || McpResource::issuer() !== 'https://bambodia.com')) {
            return response()->json(['error' => 'temporarily_unavailable'], 503)
                ->header('Cache-Control', 'no-store');
        }

        if ($approval) {
            // Do not resolve the web guard here: this is GLOBAL middleware
            // and it executes before StartSession on real HTTP requests.
            // EnsureMcpOAuthConsentAdmin checks role/status on Passport's
            // POST authorization route AFTER the browser session is loaded.
            return $next($request);
        }

        if (! $authorization && ! $exchange) {
            return $next($request);
        }

        $resource = $authorization ? $request->query('resource') : $request->input('resource');
        if (! McpResource::matches($resource)) {
            return $this->denied('invalid_target', 'The Bambodia MCP resource is required.');
        }

        if ($exchange && ! in_array($request->input('grant_type'), ['authorization_code', 'refresh_token'], true)) {
            return $this->denied('unsupported_grant_type', 'Only authorization code and refresh grants are supported.');
        }

        return $next($request);
    }

    private function denied(string $code, string $description): JsonResponse
    {
        return response()->json(['error' => $code, 'error_description' => $description], 400)
            ->header('Cache-Control', 'no-store');
    }
}
