<?php

namespace Tests\Feature;

use App\Models\Policy;
use App\Models\User;
use App\Support\MarketplaceServiceLegalDrafts;
use App\Support\SupplementalLegalPolicyDrafts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceServiceLegalDraftsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $email): User
    {
        return User::query()->create([
            'name' => 'Legal Coverage QA',
            'email' => $email,
            'password' => 'Password123!',
            'role' => $role,
        ]);
    }

    public function test_catalog_has_eleven_distinct_substantive_unpublished_review_templates(): void
    {
        $templates = MarketplaceServiceLegalDrafts::all();
        $this->assertCount(11, $templates);
        $this->assertCount(11, array_unique(array_column($templates, 'title')));
        $this->assertCount(18, array_unique(array_merge(
            array_column(SupplementalLegalPolicyDrafts::all(), 'title'),
            array_column($templates, 'title'),
        )));

        $categories = [];
        foreach ($templates as $template) {
            $this->assertContains($template['category'], ['commerce', 'terms', 'privacy']);
            $categories[] = $template['category'];
            $this->assertGreaterThan(1200, mb_strlen($template['body']));
            $this->assertLessThanOrEqual(100000, mb_strlen($template['body']));
            $this->assertStringContainsString('Draft for legal review', $template['body']);
            $this->assertStringContainsString('NOT PUBLISHED', $template['body']);
            $this->assertStringContainsString('## Legal review and controlling rights', $template['body']);
            $this->assertGreaterThanOrEqual(6, substr_count($template['body'], "\n## "));
            $this->assertSame(
                'Cambodia / international customers — qualified legal review required',
                $template['jurisdiction']
            );
        }
        $this->assertContains('privacy', $categories);
        $this->assertContains('terms', $categories);
        $this->assertContains('commerce', $categories);
    }

    public function test_compliance_seed_creates_missing_drafts_without_overwriting_existing_policies(): void
    {
        $admin = $this->user('super_admin', 'coverage-admin@example.test');
        $existing = Policy::query()->create([
            'title' => 'Terms of Service',
            'category' => 'terms',
            'body' => str_repeat('Previously reviewed published marketplace terms. ', 6),
            'status' => 'published',
            'body_format' => 'markdown',
        ]);
        $reviewed = Policy::query()->create([
            'title' => 'Trader and Creator Identity Disclosure Policy',
            'category' => 'terms',
            'body' => str_repeat('Administrator-customized draft that must be preserved. ', 5),
            'status' => 'draft',
            'body_format' => 'markdown',
        ]);
        $customer = $this->user('customer', 'coverage-customer@example.test');
        $this->post(route('dashboard.policies.supplemental-drafts'))->assertRedirect(route('login'));
        $this->actingAs($customer)->post(route('dashboard.policies.supplemental-drafts'))->assertForbidden();
        $this->assertDatabaseCount('policies', 2);

        $this->actingAs($admin)->post(route('dashboard.policies.supplemental-drafts'))->assertRedirect();
        $this->assertDatabaseCount('policies', 19);
        $this->assertSame(18, Policy::query()->where('status', 'draft')->count());
        $this->assertSame('published', $existing->fresh()->status);
        $this->assertSame(str_repeat('Previously reviewed published marketplace terms. ', 6), $existing->fresh()->body);
        $this->assertSame(str_repeat('Administrator-customized draft that must be preserved. ', 5), $reviewed->fresh()->body);
        $this->assertDatabaseCount('policy_publications', 0);

        $this->post(route('dashboard.policies.supplemental-drafts'))->assertRedirect();
        $this->assertDatabaseCount('policies', 19);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'policy.supplemental_drafts_created', 'actor_id' => $admin->id,
        ]);
        $this->get(route('policy-center'))->assertOk()
            ->assertSee('Terms of Service')
            ->assertDontSee('Jobs and Recruitment Listings Policy')
            ->assertDontSee('Data Retention, Account Closure and Privacy Requests Policy');

        $draft = Policy::query()->where('title', 'Jobs and Recruitment Listings Policy')->firstOrFail();
        $this->get($draft->publicUrl())->assertNotFound();
        $this->get(route('dashboard.policies.edit', $draft))->assertOk()
            ->assertSee($draft->title);
    }

    public function test_unreviewed_template_cannot_be_published_with_checkbox_alone(): void
    {
        $admin = $this->user('super_admin', 'publication-admin@example.test');
        $this->actingAs($admin)->post(route('dashboard.policies.supplemental-drafts'))->assertRedirect();

        $draft = Policy::query()->where('title', 'Digital Product Quality, Malware and File Safety Policy')->firstOrFail();
        $this->patch(route('dashboard.policies.update', $draft), [
            'title' => $draft->title,
            'body' => $draft->body,
            'body_format' => 'markdown',
            'category' => $draft->category,
            'jurisdiction' => $draft->jurisdiction,
            'version' => $draft->version,
            'status' => 'published',
            'confirm_legal_review' => '1',
        ])->assertSessionHasErrors('body');

        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertDatabaseCount('policy_publications', 0);
    }
}
