<?php

namespace Tests\Feature;

use App\Models\AiDraftProvider;
use App\Models\AiDraftRequest;
use App\Models\MarketplaceListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AiDraftStudioTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role): User
    {
        return User::query()->create([
            'name' => 'AI Studio '.$role,
            'email' => uniqid($role, true).'@example.test',
            'password' => 'StrongAiDraftPassword123!',
            'role' => $role,
        ]);
    }

    private function provider(array $attributes = []): AiDraftProvider
    {
        return AiDraftProvider::query()->create(array_replace([
            'id' => 1,
            'provider' => 'openai',
            'model' => 'gpt-4.1-mini',
            'api_key' => 'sk-test-private-provider-key-for-tests',
            'daily_limit' => 5,
            'global_daily_limit' => 20,
            'is_enabled' => true,
            'verified_at' => now(),
        ], $attributes));
    }

    private function validRequest(array $changes = []): array
    {
        return array_replace([
            'type' => 'service',
            'reference' => null,
            'brief' => 'I build and review Laravel applications and deliver a code audit with a clear written summary.',
            'tone' => 'professional',
            'locale' => 'en',
            'consent' => true,
        ], $changes);
    }

    private function fakeGeneration(bool $success = true): void
    {
        if (! $success) {
            Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'secret raw provider error']], 503)]);
            return;
        }

        Http::fake(['api.openai.com/*' => Http::response([
            'id' => 'resp_test_generation',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'title' => 'Professional Laravel Application Audit',
                        'description' => str_repeat('I deliver an independent review of a Laravel application with a clear written report. ', 3),
                    ]),
                ]],
            ]],
        ], 200)]);
    }

    public function test_provider_requires_super_admin_and_never_displays_the_secret(): void
    {
        $this->get('/dashboard/ai-draft-studio')->assertRedirect('/login');
        foreach (['staff', 'admin', 'customer', 'freelancer'] as $role) {
            $this->actingAs($this->member($role));
            $this->get('/dashboard/ai-draft-studio')->assertForbidden();
            $this->post('/dashboard/ai-draft-studio', [
                'model' => 'gpt-4.1-mini', 'api_key' => 'sk-something-private-123456',
                'daily_limit' => 5, 'global_daily_limit' => 100,
            ])->assertForbidden();
            $this->post('/dashboard/ai-draft-studio/test')->assertForbidden();
            $this->patch('/dashboard/ai-draft-studio/status', ['is_enabled' => 1])->assertForbidden();
            $this->delete('/dashboard/ai-draft-studio')->assertForbidden();
        }
        $admin = $this->member('super_admin');
        $this->actingAs($admin);
        $response = $this->post('/dashboard/ai-draft-studio', [
            'model' => 'gpt-4.1-mini',
            'api_key' => 'sk-private-hidden-administrator-credential',
            'daily_limit' => 3, 'global_daily_limit' => 15,
        ])->assertRedirect();
        $provider = AiDraftProvider::configured();
        $this->assertNotNull($provider);
        $this->assertSame('sk-private-hidden-administrator-credential', $provider->api_key);
        $this->assertNotSame($provider->api_key, $provider->getRawOriginal('api_key'));
        $this->assertFalse($provider->is_enabled);
        $this->assertNull($provider->verified_at);
        $this->get('/dashboard/ai-draft-studio')->assertOk()
            ->assertSee('AI Draft Studio')->assertSee('Encrypted credential stored')
            ->assertDontSee('sk-private-hidden-administrator-credential');
        $this->assertDatabaseHas('audit_events', ['event'=>'ai.provider.configured','actor_id'=>$admin->id]);
    }

    public function test_connection_test_and_explicit_enable_disable_work_without_key_exposure(): void
    {
        $owner = $this->member('super_admin');
        $this->actingAs($owner);
        $this->provider(['is_enabled' => false, 'verified_at' => null]);

        $this->patch('/dashboard/ai-draft-studio/status', ['is_enabled' => 1])
            ->assertStatus(422);
        Http::fake(['api.openai.com/*' => Http::response([
            'id' => 'resp_live_test', 'status' => 'completed', 'output' => [],
        ], 200)]);
        $this->post('/dashboard/ai-draft-studio/test')->assertRedirect()
            ->assertSessionHas('status');
        $this->assertNotNull(AiDraftProvider::configured()->verified_at);
        Http::assertSent(fn ($request) =>
            $request->url() === 'https://api.openai.com/v1/responses'
            && $request['store'] === false
            && $request['max_output_tokens'] === 40
        );
        $this->patch('/dashboard/ai-draft-studio/status', ['is_enabled' => 1])
            ->assertRedirect();
        $this->assertTrue(AiDraftProvider::configured()->is_enabled);
        $this->patch('/dashboard/ai-draft-studio/status', ['is_enabled' => 0])
            ->assertRedirect();
        $this->assertFalse(AiDraftProvider::configured()->is_enabled);
    }

    public function test_generate_returns_editable_suggestion_without_saving_any_listing_or_prompt(): void
    {
        $author = $this->member('freelancer');
        $this->provider();
        $this->fakeGeneration();
        $response = $this->actingAs($author)->postJson('/builder/ai-drafts', $this->validRequest())
            ->assertOk()
            ->assertJsonPath('title', 'Professional Laravel Application Audit')
            ->assertJsonPath('remaining', 4);

        $id = (int) $response->json('id');
        $this->assertDatabaseHas('ai_draft_requests', [
            'id'=>$id, 'user_id'=>$author->id, 'content_type'=>'service',
            'status'=>'completed', 'input_chars'=>mb_strlen($this->validRequest()['brief']),
        ]);
        $this->assertDatabaseCount('marketplace_listings', 0);
        $request = AiDraftRequest::query()->findOrFail($id);
        $this->assertArrayNotHasKey('brief', $request->getAttributes());
        $this->assertArrayNotHasKey('description', $request->getAttributes());
        $this->assertArrayNotHasKey('api_key', $request->getAttributes());
        $this->assertDatabaseHas('audit_events', ['event'=>'ai.draft.generated']);
        Http::assertSent(fn ($request) =>
            $request->url() === 'https://api.openai.com/v1/responses'
            && $request['store'] === false
            && $request['text']['format']['type'] === 'json_schema'
            && $request['model'] === 'gpt-4.1-mini'
        );

        $other = $this->member('freelancer');
        $this->actingAs($other)->postJson('/builder/ai-drafts/'.$id.'/reviewed',
            ['confirmed'=>true])->assertNotFound();

        $this->actingAs($author)->postJson('/builder/ai-drafts/'.$id.'/reviewed',
            ['confirmed'=>true])->assertOk()->assertJsonPath('reviewed', true);
        $this->assertNotNull($request->fresh()->reviewed_at);
        $this->assertDatabaseHas('audit_events', ['event'=>'ai.draft.reviewed']);
        $this->assertDatabaseCount('marketplace_listings', 0);
    }

    public function test_disabled_provider_and_missing_consent_do_not_call_external_api(): void
    {
        $this->provider(['is_enabled' => false]);
        Http::fake();
        $author = $this->member('freelancer');
        $this->actingAs($author)->postJson('/builder/ai-drafts',
            $this->validRequest())->assertStatus(409);
        $this->assertDatabaseCount('ai_draft_requests', 0);
        AiDraftProvider::configured()->update(['is_enabled'=>true]);
        $this->postJson('/builder/ai-drafts', $this->validRequest(['consent'=>false]))
            ->assertUnprocessable()->assertJsonValidationErrors('consent');
        $this->postJson('/builder/ai-drafts', $this->validRequest(['brief'=>'too short']))
            ->assertUnprocessable()->assertJsonValidationErrors('brief');
        $this->assertDatabaseCount('ai_draft_requests', 0);
        Http::assertNothingSent();
    }

    public function test_account_and_global_daily_limits_are_enforced_before_network_calls(): void
    {
        $this->provider(['daily_limit'=>2, 'global_daily_limit'=>3]);
        $this->fakeGeneration();
        $author = $this->member('freelancer');
        $other = $this->member('freelancer');
        $this->actingAs($author);
        $this->postJson('/builder/ai-drafts', $this->validRequest())->assertOk();
        $this->postJson('/builder/ai-drafts', $this->validRequest())->assertOk();
        $this->postJson('/builder/ai-drafts', $this->validRequest())->assertStatus(429);

        $this->actingAs($other);
        $this->postJson('/builder/ai-drafts', $this->validRequest())->assertOk();
        $this->postJson('/builder/ai-drafts', $this->validRequest())->assertStatus(429);

        $this->assertDatabaseCount('ai_draft_requests', 3);
        Http::assertSentCount(3);
    }

    public function test_failed_provider_request_is_safe_and_still_consumes_a_limited_attempt(): void
    {
        $this->provider(['daily_limit'=>1]);
        $this->fakeGeneration(false);
        $author = $this->member('freelancer');
        $this->actingAs($author)->postJson('/builder/ai-drafts', $this->validRequest())
            ->assertStatus(502)->assertDontSee('secret raw provider error');
        $this->assertDatabaseHas('ai_draft_requests', [
            'user_id'=>$author->id,'status'=>'failed',
        ]);
        $this->postJson('/builder/ai-drafts', $this->validRequest())->assertStatus(429);
        Http::assertSentCount(1);
    }

    public function test_builder_access_denies_unauthorized_types_and_other_owners_records(): void
    {
        $this->provider();
        Http::fake();
        $customer = $this->member('customer');
        $creator = $this->member('freelancer');
        $this->actingAs($customer)->postJson('/builder/ai-drafts',
            $this->validRequest())->assertForbidden();
        $this->actingAs($creator)->postJson('/builder/ai-drafts',
            $this->validRequest(['type'=>'job']))->assertForbidden();

        $listing = MarketplaceListing::query()->create([
            'user_id'=>$creator->id, 'type'=>'service',
            'title'=>'A secure Laravel application review',
            'description'=>str_repeat('Original application review and verified deliverables. ', 2),
            'status'=>'draft',
        ]);
        $this->actingAs($customer)->postJson('/builder/ai-drafts', $this->validRequest([
            'type'=>'service', 'reference'=>$listing->public_id,
        ]))->assertForbidden();
        $other = $this->member('freelancer');
        $this->actingAs($other)->postJson('/builder/ai-drafts', $this->validRequest([
            'reference'=>$listing->public_id,
        ]))->assertNotFound();

        $listing->update(['status'=>'published']);
        $this->actingAs($creator)->postJson('/builder/ai-drafts', $this->validRequest([
            'reference'=>$listing->public_id,
        ]))->assertStatus(409);
        $this->assertDatabaseCount('ai_draft_requests', 0);
        Http::assertNothingSent();
    }

    public function test_builder_modal_is_only_for_editable_records_and_requires_live_provider(): void
    {
        $author = $this->member('super_admin');
        $this->actingAs($author)->get('/builder?type=service')->assertOk()
            ->assertSee('AI provider not enabled by administrator')
            ->assertSee('data-ai-draft-dialog', false)
            ->assertSee('data-ai-generate-form', false);

        $this->provider();
        $this->get('/builder?type=service')->assertOk()
            ->assertSee('AI Draft')->assertSee('data-generate-url', false)
            ->assertSee('data-ai-apply', false);
        $this->get('/')->assertOk()->assertDontSee('data-ai-draft-dialog', false);
    }

    public function test_disconnect_removes_secret_but_retains_metadata_history(): void
    {
        $owner = $this->member('super_admin');
        $this->provider();
        AiDraftRequest::query()->create([
            'user_id'=>$owner->id,'content_type'=>'job','model'=>'gpt-4.1-mini',
            'status'=>'completed','input_chars'=>80,'output_chars'=>400,
        ]);
        $this->actingAs($owner)->delete('/dashboard/ai-draft-studio')
            ->assertRedirect();
        $this->assertFalse(AiDraftProvider::configured()->is_enabled);
        $this->assertNull(AiDraftProvider::configured()->api_key);
        $this->assertDatabaseCount('ai_draft_requests',1);
    }
}
