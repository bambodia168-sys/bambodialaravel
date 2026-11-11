<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\ContentEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

final class BambodiaMcpOAuthTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'super_admin'): User
    {
        return User::query()->create([
            'name' => 'OAuth Acceptance',
            'email' => uniqid('oauth-', true).'@example.test',
            'password' => 'TestPassword123!',
            'role' => $role,
            'account_status' => 'active',
        ]);
    }

    private function rpc(string $method, array $params = []): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params];
    }

    /**
     * Issue a real Passport JWT via restricted client registration, explicit
     * consent and RFC 7636 S256 exchange. No synthetic Passport::actingAs()
     * bypass is used for actual MCP authentication checks.
     */
    private function authorizedBearer(User $actor, string $scope): string
    {
        $callback = 'https://chatgpt.com/connector/oauth/qa-callback';
        $registered = $this->postJson('/oauth/register', [
            'client_name' => 'Bambodia Security Acceptance',
            'redirect_uris' => [$callback],
        ])->assertCreated();

        $clientId = $registered->json('client_id');
        $verifier = \Illuminate\Support\Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = \Illuminate\Support\Str::random(36);
        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $callback,
            'response_type' => 'code',
            'scope' => $scope,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => url('/mcp'),
        ]);

        $this->actingAs($actor, 'web')
            ->get('/oauth/authorize?'.$query)
            ->assertOk();
        $authToken = session('authToken');
        $this->assertIsString($authToken);

        $approval = $this->post(route('passport.authorizations.approve'), [
            'client_id' => $clientId,
            'state' => $state,
            'auth_token' => $authToken,
        ])->assertRedirect();
        parse_str(parse_url($approval->headers->get('Location'), PHP_URL_QUERY) ?: '', $params);
        $this->assertSame($state, $params['state'] ?? null);

        $exchange = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $params['code'],
            'redirect_uri' => $callback,
            'code_verifier' => $verifier,
            'resource' => url('/mcp'),
        ])->assertOk();

        $secret = $exchange->json('access_token');
        $this->assertIsString($secret);
        \Illuminate\Support\Facades\Auth::forgetGuards();

        return $secret;
    }

    public function test_oauth_resource_indicator_is_required_on_authorization_and_token_requests(): void
    {
        $this->get('/oauth/authorize')->assertStatus(400)
            ->assertJsonPath('error', 'invalid_target');

        $this->get('/oauth/authorize?'.http_build_query([
            'resource' => 'https://other.example/mcp',
        ]))->assertStatus(400)->assertJsonPath('error', 'invalid_target');

        $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_target');

        $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'resource' => 'https://other.example/mcp',
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_target');

        $this->postJson('/oauth/token', [
            'grant_type' => 'password',
            'resource' => url('/mcp'),
        ])->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');

        $this->assertTrue(\App\Support\Mcp\McpResource::matches(url('/mcp')));
        $this->assertFalse(\App\Support\Mcp\McpResource::matches(url('/')));
        $this->assertFalse(\App\Support\Mcp\McpResource::matches(url('/mcp').'?evil=1'));
    }

    public function test_validly_signed_oauth_token_without_mcp_audience_is_rejected(): void
    {
        $user = $this->user();

        // This uses Passport's authentic, signed default access-token entity,
        // whose sole audience is its OAuth client (not the MCP resource).
        \Laravel\Passport\Passport::useAccessTokenEntity(\Laravel\Passport\Bridge\AccessToken::class);
        try {
            $bearer = $this->authorizedBearer($user, 'mcp:use');
        } finally {
            \Laravel\Passport\Passport::useAccessTokenEntity(\App\Support\Mcp\BambodiaPassportAccessToken::class);
        }

        $this->withToken($bearer)
            ->postJson('/mcp', $this->rpc('initialize'))
            ->assertUnauthorized();

        $this->assertDatabaseCount('oauth_access_tokens', 1);
    }

    public function test_missing_token_returns_protected_resource_discovery_challenge(): void
    {
        $this->postJson('/mcp', $this->rpc('initialize'))
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate',
                'Bearer resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'", scope="mcp:use"');
    }

    public function test_mcp_oauth_discovery_metadata_uses_s256_and_canonical_resource(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource/mcp')
            ->assertOk()
            ->assertJsonPath('resource', url('/mcp'))
            ->assertJsonPath('authorization_servers.0', url('/'));

        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('authorization_endpoint', route('passport.authorizations.authorize'))
            ->assertJsonPath('token_endpoint', route('passport.token'))
            ->assertJsonPath('registration_endpoint', route('mcp.oauth.register'))
            ->assertJsonPath('code_challenge_methods_supported.0', 'S256');

        $registration = \Illuminate\Support\Facades\Route::getRoutes()
            ->getByName('mcp.oauth.register');
        $this->assertNotNull($registration);
        $this->assertContains(
            \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
            $registration->excludedMiddleware()
        );
        $this->assertSame(['https://chatgpt.com'], config('mcp.redirect_domains'));
    }

    public function test_dynamic_client_registration_accepts_chatgpt_only(): void
    {
        $registration = $this->postJson('/oauth/register', [
            'client_name' => 'Bambodia Manager QA',
            'redirect_uris' => ['https://chatgpt.com/connector/oauth/qa-callback'],
        ])->assertCreated()
            ->assertJsonPath('token_endpoint_auth_method', 'none')
            ->assertJsonPath('scope', 'mcp:use');
        $this->assertDatabaseCount('oauth_clients', 1);

        $client = Passport::client()->newQuery()->findOrFail($registration->json('client_id'));
        $this->assertContains('mcp:use', $client->scopes);
        $this->assertContains('mcp.write', $client->scopes);
        // The default advertised scope remains read-only; writes need extra consent.
        $this->assertNotSame('mcp:use mcp.write', $registration->json('scope'));

        $this->postJson('/oauth/register', [
            'client_name' => 'Untrusted Integration',
            'redirect_uris' => ['https://untrusted.example/steal'],
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        $this->assertDatabaseCount('oauth_clients', 1);
    }

    public function test_legacy_read_only_token_remains_usable_without_write_permissions(): void
    {
        [$token, $secret] = ApiToken::issue($this->user(), 'Read-only MCP acceptance', ['mcp.read'], 7);
        $this->withToken($secret)
            ->postJson('/mcp', $this->rpc('tools/list'))
            ->assertOk()
            ->assertJsonCount(4, 'result.tools')
            ->assertJsonPath('result.tools.0._meta.securitySchemes.0.type', 'oauth2')
            ->assertJsonPath('result.tools.0._meta.securitySchemes.0.scopes.0', 'mcp:use');
        $this->assertNotNull($token->fresh()->last_used_at);
    }

    public function test_all_four_read_tools_execute_against_isolated_records(): void
    {
        $actor = $this->user();
        $post = ContentEntry::query()->create([
            'type' => 'post',
            'title' => 'Acceptance test content',
            'body' => 'The isolated content used to verify MCP read-only fetch.',
            'status' => 'draft',
            'author_id' => $actor->id,
        ]);
        [, $secret] = ApiToken::issue($actor, 'Read tool acceptance', ['mcp.read'], 7);
        $this->withToken($secret);

        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name' => 'bambodia_overview',
        ]))->assertOk()->assertJsonPath('result.isError', false);

        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name' => 'bambodia_list',
            'arguments' => ['entity' => 'post', 'search' => 'Acceptance test'],
        ]))->assertOk()->assertJsonPath('result.structuredContent.total', 1);

        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name' => 'bambodia_get',
            'arguments' => ['entity' => 'post', 'id' => $post->id],
        ]))->assertOk()->assertJsonPath('result.structuredContent.title', $post->title);

        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name' => 'bambodia_analytics',
            'arguments' => ['days' => 7],
        ]))->assertOk()->assertJsonPath('result.structuredContent.period_days', 7);

        $this->assertDatabaseHas('content_entries', ['id' => $post->id, 'status' => 'draft']);
    }

    public function test_real_pkce_authorization_code_exchange_and_bearer_tool_discovery(): void
    {
        $actor = $this->user();
        $callback = 'https://chatgpt.com/connector/oauth/qa-callback';
        $registration = $this->postJson('/oauth/register', [
            'client_name' => 'Bambodia Manager PKCE QA',
            'redirect_uris' => [$callback],
        ])->assertCreated();

        $clientId = $registration->json('client_id');
        $state = \Illuminate\Support\Str::random(48);
        $verifier = \Illuminate\Support\Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $callback,
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => url('/mcp'),
        ]);

        $this->actingAs($actor);
        $authorization = $this->get('/oauth/authorize?'.$query)
            ->assertOk()
            ->assertSee('Connect Bambodia Manager');

        $authToken = session('authToken');
        $this->assertIsString($authToken);
        $this->assertNotEmpty($authToken);

        $approval = $this->post(route('passport.authorizations.approve'), [
            'state' => $state,
            'client_id' => $clientId,
            'auth_token' => $authToken,
        ])->assertRedirect();

        parse_str(parse_url($approval->headers->get('Location'), PHP_URL_QUERY) ?: '', $callbackQuery);
        $this->assertSame($state, $callbackQuery['state'] ?? null);
        $this->assertNotEmpty($callbackQuery['code'] ?? null);

        $exchange = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => $callback,
            'code' => $callbackQuery['code'],
            'code_verifier' => $verifier,
            'resource' => url('/mcp'),
        ])->assertOk();

        $accessToken = $exchange->json('access_token');
        $this->assertIsString($accessToken);
        $parts = explode('.', $accessToken);
        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(url('/mcp'), $claims['aud'][1] ?? null);
        $this->assertSame(rtrim(config('app.url'), '/'), $claims['iss'] ?? null);
        $this->assertNotEmpty($accessToken);

        $this->withToken($accessToken)
            ->postJson('/mcp', $this->rpc('tools/list'))
            ->assertOk()
            ->assertJsonCount(4, 'result.tools');

        $refreshToken = $exchange->json('refresh_token');
        $this->assertNotEmpty($refreshToken);

        $refreshed = $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $refreshToken,
            'scope' => 'mcp:use',
            'resource' => url('/mcp'),
        ])->assertOk();

        $nextAccessToken = $refreshed->json('access_token');
        $nextRefreshToken = $refreshed->json('refresh_token');
        $this->assertNotEmpty($nextAccessToken);
        $this->assertNotEmpty($nextRefreshToken);

        $this->withToken($nextAccessToken)
            ->postJson('/mcp', $this->rpc('tools/list'))
            ->assertOk()->assertJsonCount(4, 'result.tools');

        // Decode only the test-generated JWT identifier (not a verification
        // substitute). The real MCP middleware verifies the signed token.
        $parts = explode('.', $nextAccessToken);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true, 512, JSON_THROW_ON_ERROR);
        $tokenId = $payload['jti'];

        $this->actingAs($actor)->delete('/developer/oauth-connections/'.$tokenId, [
            'password' => 'TestPassword123!',
        ])->assertRedirect();

        $this->assertTrue(Passport::token()->newQuery()->findOrFail($tokenId)->revoked);
        $this->assertTrue(Passport::refreshToken()->newQuery()
            ->where('access_token_id', $tokenId)->firstOrFail()->revoked);

        // PHPUnit reuses one Laravel container for several synthetic requests.
        // Clear the resolved guard so a fresh request rechecks Passport's
        // database-backed revocation state, as production requests must.
        \Illuminate\Support\Facades\Auth::forgetGuards();

        $this->withToken($nextAccessToken)
            ->postJson('/mcp', $this->rpc('tools/list'))
            ->assertUnauthorized();

        $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $nextRefreshToken,
        ])->assertStatus(400);
    }

    public function test_real_oauth_read_scope_cannot_discover_or_execute_any_write_tool(): void
    {
        $bearer = $this->authorizedBearer($this->user(), 'mcp:use');
        $this->withToken($bearer)
            ->postJson('/mcp', $this->rpc('tools/list'))
            ->assertOk()
            ->assertJsonCount(4, 'result.tools');

        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name' => 'bambodia_create_draft',
            'arguments' => ['entity' => 'service', 'fields' => [
                'title' => 'Forbidden service',
                'description' => str_repeat('Read-only clients cannot create listings. ', 2),
            ]],
        ]))->assertOk()->assertJsonPath('result.isError', true);

        $this->assertDatabaseCount('marketplace_listings', 0);
    }

    public function test_real_oauth_write_scope_exposes_all_ten_tools_only_for_super_admin(): void
    {
        $bearer = $this->authorizedBearer($this->user(), 'mcp:use mcp.write');
        $response = $this->withToken($bearer)
            ->postJson('/mcp', $this->rpc('tools/list'))
            ->assertOk()
            ->assertJsonCount(10, 'result.tools');

        $this->assertSame(
            ['mcp:use', 'mcp.write'],
            collect($response->json('result.tools'))
                ->firstWhere('name', 'bambodia_create_draft')['_meta']['securitySchemes'][0]['scopes']
        );
    }

    public function test_oauth_without_read_scope_or_with_demoted_administrator_is_denied(): void
    {
        $actor = $this->user();
        $writeOnly = $this->authorizedBearer($actor, 'mcp.write');
        $this->withToken($writeOnly)
            ->postJson('/mcp', $this->rpc('initialize'))
            ->assertUnauthorized();

        $allowed = $this->authorizedBearer($actor, 'mcp:use');
        $actor->forceFill(['role' => 'customer'])->save();
        \Illuminate\Support\Facades\Auth::forgetGuards();

        $this->withToken($allowed)
            ->postJson('/mcp', $this->rpc('initialize'))
            ->assertForbidden();
    }

    public function test_authorization_provider_exposes_dedicated_scopes_and_short_token_lifetime(): void
    {
        $scopes = \Laravel\Passport\Passport::scopes();
        $names = $scopes->map(fn ($scope) => $scope->id)->all();
        $this->assertContains('mcp:use', $names);
        $this->assertContains('mcp.write', $names);
        $this->assertContains('offline_access', $names);
    }
}
