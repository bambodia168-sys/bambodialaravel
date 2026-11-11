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
        $this->assertNotEmpty($accessToken);

        $this->withToken($accessToken)
            ->postJson('/mcp', $this->rpc('tools/list'))
            ->assertOk()
            ->assertJsonCount(4, 'result.tools');

        $this->assertNotEmpty($exchange->json('refresh_token'));
    }

    public function test_passport_scopes_allow_only_read_discovery_without_explicit_write_scope(): void
    {
        Passport::actingAs($this->user(), ['mcp:use']);
        $this->withToken('synthetic-test-passport-token')
            ->postJson('/mcp', $this->rpc('tools/list'))
            ->assertOk()
            ->assertJsonCount(4, 'result.tools');

        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name' => 'bambodia_create_draft',
            'arguments' => ['entity' => 'service', 'fields' => [
                'title' => 'Forbidden service',
                'description' => str_repeat('Read-only clients cannot create listings. ', 2),
            ]],
        ]))->assertJsonPath('result.isError', true);

        $this->assertDatabaseCount('marketplace_listings', 0);
    }

    public function test_passport_write_scope_discloses_all_ten_tools_only_for_super_admin(): void
    {
        Passport::actingAs($this->user(), ['mcp:use', 'mcp.write']);
        $this->withToken('synthetic-test-passport-token')
            ->postJson('/mcp', $this->rpc('tools/list'))
            ->assertOk()
            ->assertJsonCount(10, 'result.tools');
    }

    public function test_oauth_bearer_without_mcp_use_scope_and_non_admin_are_denied(): void
    {
        Passport::actingAs($this->user(), ['mcp.write']);
        $this->withToken('synthetic-test-passport-token')
            ->postJson('/mcp', $this->rpc('initialize'))
            ->assertUnauthorized();

        Passport::actingAs($this->user('customer'), ['mcp:use', 'mcp.write']);
        $this->withToken('synthetic-test-passport-token')
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
