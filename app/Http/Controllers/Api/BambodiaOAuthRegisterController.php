<?php

namespace App\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

/**
 * Authorize write consent as an OPTIONAL scope for a registered ChatGPT OAuth
 * client; never automatically request or grant it to the user.
 */
final class BambodiaOAuthRegisterController extends OAuthRegisterController
{
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
