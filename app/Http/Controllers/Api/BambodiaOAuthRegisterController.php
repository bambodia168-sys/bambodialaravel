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
        return parent::__invoke($request);
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

        $client->forceFill([
            'scopes' => array_values(array_unique([...$scopes, 'mcp.write', 'offline_access'])),
        ])->save();
    }
}
