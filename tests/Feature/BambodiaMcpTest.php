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

    public function test_analytics_is_read_only_and_available_to_read_only_token(): void
    {
        [$token, $secret] = ApiToken::issue($this->admin(), 'Read analytics', ['mcp.read'], 7);
        $this->withToken($secret);
        $tools = $this->postJson('/mcp', $this->rpc('tools/list'))->json('result.tools');
        $this->assertContains('bambodia_analytics', collect($tools)->pluck('name')->all());
        $this->assertNotContains('bambodia_manage_site', collect($tools)->pluck('name')->all());
        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name' => 'bambodia_analytics', 'arguments' => ['days' => 7],
        ]))->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.period_days', 7);
    }

    public function test_own_profile_update_is_scoped_to_token_owner(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        [$token, $secret] = ApiToken::issue($admin, 'Profile update', ['mcp.read','mcp.write'], 7);
        $this->withToken($secret);

        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name' => 'bambodia_update_own_profile',
            'arguments' => ['fields' => [
                'headline' => 'Bambodia Platform Director',
                'biography' => str_repeat('Managing the Bambodia platform with a focus on trust, safety and quality. ', 2),
                'website_url' => 'https://bambodia.com',
                'is_public' => false,
            ]],
        ]))->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.user_id', $admin->id);
        $this->assertDatabaseHas('creator_profiles', ['user_id'=>$admin->id,'is_public'=>false]);
        $this->assertDatabaseMissing('creator_profiles', ['user_id'=>$other->id]);

        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name' => 'bambodia_update_own_profile',
            'arguments' => ['fields' => ['user_id' => $other->id, 'is_public' => true]],
        ]))->assertJsonPath('result.isError', true);
        $this->assertDatabaseMissing('creator_profiles', ['user_id'=>$other->id]);
    }

    public function test_mcp_seo_crud_requires_explicit_active_change_confirmation(): void
    {
        [$token, $secret] = ApiToken::issue($this->admin(), 'SEO update', ['mcp.read','mcp.write'], 7);
        $this->withToken($secret);
        $created = $this->postJson('/mcp', $this->rpc('tools/call', [
            'name'=>'bambodia_manage_site','arguments'=>[
                'entity'=>'seo','action'=>'create','fields'=>[
                    'path'=>'/example-landing',
                    'locale'=>'en','title'=>'Example landing page',
                    'description'=>'A public landing page for examples and guidance.',
                    'is_active'=>false,'is_indexable'=>false,
                ],
            ],
        ]))->assertOk()->assertJsonPath('result.isError', false);
        $id = $created->json('result.structuredContent.id');

        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name'=>'bambodia_manage_site','arguments'=>[
                'entity'=>'seo','action'=>'update','id'=>$id,
                'fields'=>['is_active'=>true],
            ],
        ]))->assertJsonPath('result.isError', true);
        $this->assertDatabaseHas('site_seo_pages', ['id'=>$id,'is_active'=>false]);

        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name'=>'bambodia_manage_site','arguments'=>[
                'entity'=>'seo','action'=>'update','id'=>$id,
                'fields'=>['is_active'=>true],'confirm_live_change'=>true,
            ],
        ]))->assertJsonPath('result.structuredContent.is_active', true);

        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name'=>'bambodia_manage_site','arguments'=>[
                'entity'=>'seo','action'=>'delete','id'=>$id,
                'confirm_title'=>'Example landing page',
            ],
        ]))->assertJsonPath('result.isError', true);
        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name'=>'bambodia_manage_site','arguments'=>[
                'entity'=>'seo','action'=>'delete','id'=>$id,
                'confirm_title'=>'Example landing page','confirm_live_change'=>true,
            ],
        ]))->assertJsonPath('result.structuredContent.deleted', true);
        $this->assertDatabaseMissing('site_seo_pages', ['id'=>$id]);
    }

    public function test_mcp_social_crud_and_restricted_resource_validation(): void
    {
        [$token, $secret] = ApiToken::issue($this->admin(), 'Social update', ['mcp.read','mcp.write'], 7);
        $this->withToken($secret);
        $created = $this->postJson('/mcp', $this->rpc('tools/call', [
            'name'=>'bambodia_manage_site','arguments'=>[
                'entity'=>'social','action'=>'create','fields'=>[
                    'platform'=>'linkedin','label'=>'Bambodia LinkedIn',
                    'url'=>'https://linkedin.com/company/bambodia',
                    'sort_order'=>90,'is_active'=>false,
                ],
            ],
        ]))->assertJsonPath('result.structuredContent.platform', 'linkedin');
        $id = $created->json('result.structuredContent.id');
        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name'=>'bambodia_manage_site','arguments'=>[
                'entity'=>'social','action'=>'update','id'=>$id,'fields'=>['label'=>'Official Bambodia LinkedIn'],
            ],
        ]))->assertJsonPath('result.structuredContent.label', 'Official Bambodia LinkedIn');
        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name'=>'bambodia_manage_site','arguments'=>[
                'entity'=>'social','action'=>'delete','id'=>$id,'confirm_title'=>'Official Bambodia LinkedIn',
            ],
        ]))->assertJsonPath('result.structuredContent.deleted', true);

        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name'=>'bambodia_manage_site','arguments'=>[
                'entity'=>'seo','action'=>'create','fields'=>[
                    'path'=>'/dashboard/secrets','locale'=>'en','title'=>'Private dashboards',
                    'description'=>'Private metadata must never be published.',
                    'is_active'=>false,'is_indexable'=>false,
                ],
            ],
        ]))->assertJsonPath('result.isError', true);
    }

    public function test_approved_owned_media_can_be_attached_only_to_own_draft(): void
    {
        $actor = $this->admin();
        $another = $this->admin();
        [$token, $secret] = ApiToken::issue($actor, 'Media links', ['mcp.read','mcp.write'], 7);
        $this->withToken($secret);

        $draft = MarketplaceListing::query()->create([
            'user_id'=>$actor->id,
            'type'=>'service','title'=>'Private service draft',
            'description'=>str_repeat('Private service details. ', 3),
            'status'=>'draft',
        ]);
        $live = MarketplaceListing::query()->create([
            'user_id'=>$actor->id,
            'type'=>'service','title'=>'Published service page',
            'description'=>str_repeat('Public service details. ', 3),
            'status'=>'published',
        ]);
        $ownedAsset = \App\Models\MediaAsset::query()->create([
            'user_id'=>$actor->id,'original_name'=>'approved.png',
            'storage_path'=>'marketplace/previews/test/approved.png',
            'mime_type'=>'image/png','bytes'=>1234,'width'=>300,'height'=>300,
            'alt_text'=>'Approved test asset','rights_basis'=>'owned',
            'rights_holder'=>'QA','review_status'=>'approved',
        ]);
        $foreignAsset = \App\Models\MediaAsset::query()->create([
            'user_id'=>$another->id,'original_name'=>'foreign.png',
            'storage_path'=>'marketplace/previews/test/foreign.png',
            'mime_type'=>'image/png','bytes'=>1234,'width'=>300,'height'=>300,
            'alt_text'=>'Foreign test asset','rights_basis'=>'owned',
            'rights_holder'=>'QA','review_status'=>'approved',
        ]);

        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name'=>'bambodia_draft_media','arguments'=>[
                'entity'=>'service','id'=>$draft->id,'action'=>'attach',
                'purpose'=>'cover','media_id'=>$foreignAsset->id,
            ],
        ]))->assertJsonPath('result.isError', true);

        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name'=>'bambodia_draft_media','arguments'=>[
                'entity'=>'service','id'=>$live->id,'action'=>'attach',
                'purpose'=>'cover','media_id'=>$ownedAsset->id,
            ],
        ]))->assertJsonPath('result.isError', true);

        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name'=>'bambodia_draft_media','arguments'=>[
                'entity'=>'service','id'=>$draft->id,'action'=>'attach',
                'purpose'=>'cover','media_id'=>$ownedAsset->id,
            ],
        ]))->assertJsonPath('result.structuredContent.cover_media_id', $ownedAsset->id);

        $this->postJson('/mcp', $this->rpc('tools/call', [
            'name'=>'bambodia_draft_media','arguments'=>[
                'entity'=>'service','id'=>$draft->id,'action'=>'detach',
                'purpose'=>'cover','media_id'=>$ownedAsset->id,
            ],
        ]))->assertJsonPath('result.structuredContent.cover_media_id', null);
        $this->assertDatabaseHas('marketplace_listings', ['id'=>$draft->id,'cover_media_id'=>null]);
        $this->assertDatabaseHas('audit_events', ['event'=>'mcp.media.attached']);
        $this->assertDatabaseHas('audit_events', ['event'=>'mcp.media.detached']);
    }
}
