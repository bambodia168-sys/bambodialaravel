<?php

namespace Tests\Feature;

use App\Models\AccountNotification;
use App\Models\Policy;
use App\Models\PolicyPublication;
use App\Models\PolicyUpdateNotice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyBuilderExperienceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Policy Editor', 'email' => 'editor@example.test',
            'password' => 'secret-test-password', 'role' => 'super_admin',
        ]);
    }

    private function policy(): Policy
    {
        return Policy::query()->create([
            'title' => 'Example Legal Policy',
            'body' => "# Example Legal Policy\n\n## Scope\nThis is a detailed policy that describes permission, restrictions, and support for the platform.",
            'category' => 'general', 'status' => 'draft',
            'version' => '1.0.0', 'body_format' => 'markdown',
        ]);
    }

    public function test_all_policies_get_unique_eight_digit_references_without_replacing_legacy_ids(): void
    {
        $a = $this->policy();
        $b = Policy::query()->create([
            'title' => 'Another Legal Policy', 'body' => str_repeat('Legal terms. ', 8),
            'category' => 'terms', 'status' => 'draft',
        ]);
        $this->assertMatchesRegularExpression('/^\d{8}$/', $a->public_code);
        $this->assertNotSame($a->public_code, $b->public_code);
        $this->assertMatchesRegularExpression('/^\d{9}$/', $a->public_id);
        $this->assertStringContainsString($a->public_code, $a->publicUrl());
    }

    public function test_admin_uses_full_page_builder_and_preview_instead_of_a_dialog(): void
    {
        $policy = $this->policy();
        $this->actingAs($this->admin())
            ->get(route('dashboard.policies.edit', $policy))
            ->assertOk()->assertSee('Policy content')->assertSee('Notification summary')
            ->assertSee('data-policy-editor', false)->assertDontSee('Configure policy');
        $this->get(route('dashboard.policies.preview', $policy))
            ->assertOk()->assertSee('<h1>Example Legal Policy</h1>', false)
            ->assertSee('<h2>Scope</h2>', false);
    }

    public function test_public_eight_digit_page_requires_reviewed_publication_and_sanitizes_html(): void
    {
        $policy = $this->policy();
        $this->get($policy->publicUrl())->assertNotFound();

        $admin = $this->admin();
        $this->actingAs($admin)->patch(route('dashboard.policies.update', $policy), [
            'title' => $policy->title,
            'body' => '<h1>Public Legal Terms</h1><p>This agreement describes detailed rights and valid platform responsibilities.</p><script>alert("x")</script>',
            'body_format' => 'html',
            'category' => 'general',
            'version' => '1.0.1',
            'pic_email' => 'legal@bambodia.com',
            'status' => 'published',
            'confirm_legal_review' => '1',
        ])->assertRedirect();

        $policy->refresh();
        $this->assertDatabaseHas('policy_publications', ['policy_id' => $policy->id, 'version' => '1.0.1']);
        $this->get($policy->publicUrl())->assertOk()
            ->assertSee('Public Legal Terms')->assertDontSee('<script>', false);
        $this->get('/policies?='.$policy->public_id.'-'.$policy->slug)->assertOk();
        $this->get('/policies?='.$policy->public_code.'-'.$policy->slug)
            ->assertRedirect($policy->publicUrl());
    }

    public function test_publishing_without_notification_opt_in_does_not_notify_users(): void
    {
        $policy = $this->policy();
        $admin = $this->admin();
        $this->actingAs($admin)->patch(route('dashboard.policies.update', $policy), [
            'title' => $policy->title, 'body' => $policy->body,
            'category' => 'general', 'version' => '1.0.0',
            'body_format' => 'markdown', 'status' => 'published',
            'confirm_legal_review' => '1',
        ])->assertRedirect();

        $this->assertSame(1, PolicyPublication::query()->count());
        $this->assertDatabaseCount('policy_update_notices', 0);
        $this->assertDatabaseCount('account_notifications', 0);
        $this->assertDatabaseCount('policy_notice_recipients', 0);
    }

    public function test_banner_requires_publication_and_is_visible_to_public_after_opt_in(): void
    {
        $policy = $this->policy();
        $admin = $this->admin();
        $this->actingAs($admin)->patch(route('dashboard.policies.update', $policy), [
            'title' => $policy->title, 'body' => $policy->body,
            'category' => 'general', 'version' => '1.0.0',
            'body_format' => 'markdown', 'status' => 'published',
            'confirm_legal_review' => '1',
            'show_banner' => '1',
            'notification_headline' => 'New legal policy',
            'notification_message' => 'The updated legal terms are now published for your review.',
        ])->assertRedirect();
        $this->assertSame(1, PolicyUpdateNotice::query()->count());
        $this->get(route('policy-center'))->assertOk()->assertSee('New legal policy');
        $this->assertDatabaseCount('account_notifications', 0);
    }


    public function test_account_announcement_is_delivered_once_when_worker_processes_the_notice(): void
    {
        $policy = $this->policy();
        $admin = $this->admin();
        $recipient = User::query()->create([
            'name' => 'Legal Notice Recipient', 'email' => 'recipient@example.test',
            'password' => 'test-only-password', 'role' => 'customer',
        ]);

        $this->actingAs($admin)->patch(route('dashboard.policies.update', $policy), [
            'title' => $policy->title, 'body' => $policy->body,
            'body_format' => 'markdown', 'category' => 'general',
            'version' => '1.0.0', 'status' => 'published',
            'confirm_legal_review' => '1', 'notify_account' => '1',
            'notification_headline' => 'Policy publication notice',
            'notification_message' => 'Please review the updated legal policy and keep a copy for your records.',
        ])->assertRedirect();

        $notice = PolicyUpdateNotice::query()->firstOrFail();
        $this->assertTrue($notice->notify_account);
        (new \App\Jobs\DeliverPolicyNotice($notice->id))->handle();
        (new \App\Jobs\DeliverPolicyNotice($notice->id))->handle();

        $this->assertSame(1, AccountNotification::query()
            ->where('user_id', $recipient->id)->where('event', 'policy.updated')->count());
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('policy_notice_recipients')
            ->where('policy_update_notice_id', $notice->id)->where('user_id', $recipient->id)->count());
    }

    public function test_unreviewed_draft_cannot_be_published(): void
    {
        $policy = $this->policy();
        $this->actingAs($this->admin())->patch(route('dashboard.policies.update', $policy), [
            'title' => $policy->title, 'body' => $policy->body, 'body_format' => 'markdown',
            'category' => 'general', 'status' => 'published',
        ])->assertSessionHasErrors('confirm_legal_review');
        $this->assertSame('draft', $policy->fresh()->status);
    }
}
