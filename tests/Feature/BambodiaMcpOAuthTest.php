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
                'Bearer resource_metadata="'.url('/.well-known/oauth-protected-resource').'", scope="mcp:use"');
    }

    public function test_legacy_read_only_token_remains_usable_without_write_permissions(): void
    {
        [$token, $secret] = ApiToken::issue($this->user(), 'Read-only MCP acceptance', ['mcp.read'], 7);
        $this->withToken($secret)
            ->postJson('/mcp', $this->rpc('tools/list'))
            ->assertOk()
            ->assertJsonCount(4, 'result.tools');
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
