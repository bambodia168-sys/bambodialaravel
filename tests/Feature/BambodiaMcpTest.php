<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\MarketplaceListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BambodiaMcpTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = 'super_admin'): User
    {
        return User::query()->create([
            'name' => 'MCP Integration',
            'email' => uniqid('mcp-', true).'@example.test',
            'password' => 'SafeTestPassword123!',
            'role' => $role,
        ]);
    }

    private function rpc(string $method, array $params = [], int $id = 1): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];
    }

    public function test_mcp_rejects_unauthenticated_requests_and_old_scopes(): void
    {
        $this->postJson('/mcp', $this->rpc('initialize'))->assertUnauthorized();
        [$token, $secret] = ApiToken::issue($this->admin(), 'Old account token', ['account.read'], 7);
        $this->withToken($secret)->postJson('/mcp', $this->rpc('initialize'))->assertForbidden();
        $this->assertNull($token->fresh()->last_used_at);
    }

    public function test_read_only_token_can_initialize_and_discover_only_read_tools(): void
    {
        [$token, $secret] = ApiToken::issue($this->admin(), 'MCP read', ['mcp.read'], 7);
        $this->withToken($secret)->postJson('/mcp', $this->rpc('initialize'))
            ->assertOk()->assertJsonPath('result.serverInfo.name', 'bambodia');
        $response = $this->postJson('/mcp', $this->rpc('tools/list'));
        $response->assertOk()->assertJsonPath('result.tools.0.name', 'bambodia_overview');
        $names = collect($response->json('result.tools'))->pluck('name')->all();
        $this->assertContains('bambodia_list', $names);
        $this->assertNotContains('bambodia_create_draft', $names);
        $this->assertNotNull($token->fresh()->last_used_at);

        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name' => 'bambodia_create_draft',
            'arguments' => ['entity' => 'service','fields' => [
                'title'=>'Example Service','description'=>str_repeat('Safe draft description. ', 3),
            ]],
        ]))->assertJsonPath('result.isError', true);
        $this->assertDatabaseCount('marketplace_listings', 0);
    }

    public function test_admin_can_create_update_and_remove_unpublished_service_draft(): void
    {
        [$token, $secret] = ApiToken::issue($this->admin(), 'MCP read write', ['mcp.read','mcp.write'], 7);
        $this->withToken($secret);
        $created = $this->postJson('/mcp', $this->rpc('tools/call', [
            'name' => 'bambodia_create_draft',
            'arguments' => ['entity'=>'service','fields'=>[
                'title'=>'Editing Service Draft',
                'description'=>str_repeat('Professional consulting service. ', 3),
                'price'=>125,
            ]],
        ]))->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.status', 'draft');
        $id = $created->json('result.structuredContent.id');
        $this->assertDatabaseHas('marketplace_listings', ['id'=>$id,'type'=>'service','status'=>'draft']);

        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name'=>'bambodia_update_draft',
            'arguments'=>['entity'=>'service','id'=>$id,'fields'=>['title'=>'Updated Service Draft']],
        ]))->assertJsonPath('result.structuredContent.title', 'Updated Service Draft');

        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name'=>'bambodia_delete_draft',
            'arguments'=>['entity'=>'service','id'=>$id,'confirm_title'=>'Updated Service Draft'],
        ]))->assertJsonPath('result.structuredContent.deleted', true);
        $this->assertDatabaseMissing('marketplace_listings', ['id'=>$id]);
        $this->assertDatabaseHas('audit_events', ['event'=>'mcp.draft.created']);
        $this->assertDatabaseHas('audit_events', ['event'=>'mcp.draft.updated']);
        $this->assertDatabaseHas('audit_events', ['event'=>'mcp.draft.deleted']);
    }

    public function test_mcp_rejects_publishing_and_deleting_published_content(): void
    {
        $admin = $this->admin();
        [$token, $secret] = ApiToken::issue($admin, 'MCP read write', ['mcp.read','mcp.write'], 7);
        $this->withToken($secret);
        $published = MarketplaceListing::query()->create([
            'user_id'=>$admin->id,
            'type'=>'service',
            'title'=>'Existing Live Service',
            'description'=>str_repeat('Real public service content. ', 3),
            'status'=>'published',
        ]);

        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name'=>'bambodia_update_draft',
            'arguments'=>['entity'=>'service','id'=>$published->id,'fields'=>['title'=>'Changed']],
        ]))->assertJsonPath('result.isError', true);
        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name'=>'bambodia_delete_draft',
            'arguments'=>['entity'=>'service','id'=>$published->id,'confirm_title'=>$published->title],
        ]))->assertJsonPath('result.isError', true);
        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name'=>'bambodia_create_draft',
            'arguments'=>['entity'=>'service','fields'=>[
                'title'=>'Not Published',
                'description'=>str_repeat('Safe description. ', 5),
                'status'=>'published',
            ]],
        ]))->assertJsonPath('result.isError', true);
        $this->assertDatabaseHas('marketplace_listings', ['id'=>$published->id,'title'=>'Existing Live Service']);
    }

    public function test_revoked_or_demoted_admin_cannot_use_mcp(): void
    {
        $admin = $this->admin();
        [$token, $secret] = ApiToken::issue($admin, 'MCP revoked', ['mcp.read'], 7);
        $token->update(['revoked_at'=>now()]);
        $this->withToken($secret)->postJson('/mcp', $this->rpc('tools/list'))->assertUnauthorized();

        [$anotherToken, $anotherSecret] = ApiToken::issue($admin, 'MCP role test', ['mcp.read'], 7);
        $admin->update(['role'=>'customer']);
        $this->withToken($anotherSecret)->postJson('/mcp', $this->rpc('tools/list'))->assertUnauthorized();
    }
}
