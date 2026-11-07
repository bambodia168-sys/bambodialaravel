<?php

namespace Tests\Feature;

use App\Models\Policy;
use App\Models\User;
use App\Support\LegalPolicyDrafts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalPolicyDraftsTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_has_thirteen_unique_detailed_review_drafts(): void
    {
        $templates = LegalPolicyDrafts::all();

        $this->assertCount(13, $templates);
        $this->assertCount(13, array_unique(array_column($templates, 'title')));
        $this->assertContains('Regular License', array_column($templates, 'title'));
        $this->assertContains('Extended License', array_column($templates, 'title'));
        $this->assertContains('Privacy Policy', array_column($templates, 'title'));

        foreach ($templates as $template) {
            $this->assertContains($template['category'], ['general', 'privacy', 'cookies', 'gdpr', 'terms', 'commerce']);
            $this->assertGreaterThan(1600, mb_strlen($template['body']));
            $this->assertLessThanOrEqual(100000, mb_strlen($template['body']));
        }
    }

    public function test_super_admin_can_create_missing_drafts_without_publishing_or_overwriting(): void
    {
        $admin = User::query()->create([
            'name' => 'Policy Reviewer', 'email' => 'policy-reviewer@example.test',
            'password' => 'test-only-password', 'role' => 'super_admin',
        ]);

        $this->actingAs($admin)->post(route('dashboard.policies.legal-drafts'))->assertRedirect();
        $this->assertSame(13, Policy::query()->count());
        $this->assertSame(0, Policy::query()->where('status', 'published')->count());

        $regular = Policy::query()->where('title', 'Regular License')->firstOrFail();
        $regular->update(['body' => 'Preserved administrator edits. This policy is held for review and has not yet been published.']);

        $this->actingAs($admin)->post(route('dashboard.policies.legal-drafts'))->assertRedirect();
        $this->assertSame(13, Policy::query()->count());
        $this->assertSame('Preserved administrator edits. This policy is held for review and has not yet been published.', $regular->fresh()->body);
    }

    public function test_unauthenticated_visitors_cannot_create_legal_drafts(): void
    {
        $this->post(route('dashboard.policies.legal-drafts'))->assertRedirect(route('login'));
        $this->assertDatabaseCount('policies', 0);
    }
}
