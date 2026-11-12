<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BambodiaMcpProductionGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://bambodia.com');
        config()->set('mcp.production_enabled', false);
        config()->set('mcp.production_writes_enabled', false);
    }

    private function token(): string
    {
        $admin = User::query()->create([
            'name' => 'MCP Production Gate QA',
            'email' => 'mcp-production-gate@example.test',
            'password' => 'TestPassword123!',
            'role' => 'super_admin',
            'account_status' => 'active',
        ]);
        [, $secret] = ApiToken::issue($admin, 'MCP production gate', ['mcp.read', 'mcp.write'], 7);

        return $secret;
    }

    public function test_both_well_known_resource_metadata_urls_advertise_the_same_main_domain_mcp_resource(): void
    {
        $root = $this->getJson('/.well-known/oauth-protected-resource');
        $nested = $this->getJson('/.well-known/oauth-protected-resource/mcp');

        $root->assertOk()->assertJsonPath('resource', 'https://bambodia.com/mcp');
        $nested->assertOk()->assertJsonPath('resource', 'https://bambodia.com/mcp');
        $this->assertSame(
            $root->json('authorization_servers'),
            $nested->json('authorization_servers')
        );
        $this->assertSame(['https://bambodia.com'], $root->json('authorization_servers'));
    }

    public function test_main_domain_dcr_is_disabled_by_default_even_with_valid_callback_metadata(): void
    {
        $this->postJson('/oauth/register', [
            'client_name' => 'Unapproved production connection',
            'redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect'],
        ])->assertStatus(503)->assertJsonPath('error', 'temporarily_unavailable');

        $this->assertDatabaseCount('oauth_clients', 0);
    }

    public function test_production_mcp_does_not_serve_tools_when_not_explicitly_enabled(): void
    {
        $secret = $this->token();
        $this->withToken($secret)->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => (object) [],
        ])->assertStatus(503);
    }

    public function test_write_tools_are_hidden_and_blocked_when_main_domain_is_read_only(): void
    {
        config()->set('mcp.production_enabled', true);
        $secret = $this->token();
        $tools = $this->withToken($secret)->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => (object) [],
        ]);
        $tools->assertOk();
        $names = array_column($tools->json('result.tools'), 'name');
        $this->assertContains('bambodia_overview', $names);
        $this->assertNotContains('bambodia_create_draft', $names);
        $this->assertNotContains('bambodia_manage_site', $names);

        $this->withToken($secret)->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'bambodia_create_draft',
                'arguments' => ['entity' => 'post', 'fields' => [
                    'title' => 'Must not be created',
                    'body' => 'Production write feature switch disabled.',
                ]],
            ],
        ])->assertOk()->assertJsonPath('result.isError', true);
    }
}
