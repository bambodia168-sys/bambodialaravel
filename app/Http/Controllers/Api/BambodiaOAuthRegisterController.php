<?php

namespace App\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

/**
 * Authorize write consent as an OPTIONAL scope for a registered ChatGPT OAuth
 * client; never automatically request or grant it to the user.
 */
final class BambodiaOAuthRegisterController extends OAuthRegisterController
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! config('mcp.production_enabled', false)
            || \App\Support\Mcp\McpResource::issuer() !== 'https://bambodia.com') {
            return response()->json(['error' => 'temporarily_unavailable'], 503)
                ->header('Cache-Control', 'no-store');
        }
        // The Laravel MCP SDK's redirect_domains guard accepts any path on
        // that host. Do not register arbitrary chatgpt.com paths: only the
        // exact callback-ID URL copied from ChatGPT may receive auth codes.
        // No default is authorized in production.
        $approved = config('mcp.exact_redirect_uris', []);
        $requested = $request->input('redirect_uris');
        if (! is_array($approved) || ! is_array($requested)
            || count($requested) < 1 || count($requested) > 10) {
            return $this->rejectCallback();
        }
        foreach ($requested as $uri) {
            if (! is_string($uri)
                || preg_match('~\\Ahttps://chatgpt\\.com/connector/oauth/[a-zA-Z0-9_-]{1,128}\\z~D', $uri) !== 1
                || ! in_array($uri, $approved, true)) {
                return $this->rejectCallback();
            }
        }
        return parent::__invoke($request);
    }

    private function rejectCallback(): JsonResponse
    {
        return response()->json([
            'error' => 'invalid_redirect_uri',
            'error_description' => 'The redirect URI is not approved for this connector.',
        ], 400)->header('Cache-Control', 'no-store');
    }

    protected function grantMcpScope(mixed $client): void
    {
        parent::grantMcpScope($client);

        if (! $client instanceof Model) {
            return;
        }

        $scopes = $client->refresh()->getAttribute('scopes');
        if (! is_array($scopes)) {
            $scopes = ['mcp:use'];
        }

        // Offline refresh stays separately consented. In the initial
        // read-only release, do not make write scope available to new clients.
        $permitted = [...$scopes, 'offline_access'];
        if (config('mcp.production_writes_enabled', false)) {
            $permitted[] = 'mcp.write';
        }
        $client->forceFill([
            'scopes' => array_values(array_unique($permitted)),
        ])->save();
    }
}
