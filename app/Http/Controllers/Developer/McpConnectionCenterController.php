<?php

namespace App\Http\Controllers\Developer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;

final class McpConnectionCenterController extends Controller
{
    public function index(Request $request): Response
    {
        $tablesReady = Schema::hasTable('oauth_access_tokens')
            && Schema::hasTable('oauth_clients');
        $activeConnections = $tablesReady ? Passport::token()->newQuery()
            ->where('user_id', $request->user()->id)
            ->whereJsonContains('scopes', 'mcp:use')
            ->where('revoked', false)
            ->where('expires_at', '>', now())
            ->count() : 0;

        return response()->view('developer.mcp-connection', [
            'enabled' => (bool) config('mcp.production_enabled', false),
            'writesEnabled' => (bool) config('mcp.production_writes_enabled', false),
            'oauthStorageReady' => $tablesReady,
            'activeConnections' => $activeConnections,
            'mcpUrl' => \App\Support\Mcp\McpResource::uri(),
        ])->header('Cache-Control', 'private, no-store');
    }
}
