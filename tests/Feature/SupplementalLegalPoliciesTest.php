<?php

namespace Tests\Feature;

use App\Models\Policy;
use App\Models\User;
use App\Support\SupplementalLegalPolicyDrafts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplementalLegalPoliciesTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplemental_catalog_has_seven_distinct_review_only_documents(): void
    {
        $items = SupplementalLegalPolicyDrafts::all();
        $this->assertCount(7, $items);
        $this->assertCount(7, array_unique(array_column($items, 'title')));
        foreach ($items as $item) {
            $this->assertGreaterThan(900, mb_strlen($item['body']));
            $this->assertContains($item['category'], ['terms', 'commerce']);
            $this->assertStringContainsString('Draft for legal review', $item['body']);
        }
    }

    public function test_seeding_is_idempotent_without_changing_prior_policy_records(): void
    {
        $admin = User::query()->create([
            'name' => 'Legal editor', 'email' => 'legal-editor@example.test',
            'password' => 'Password123!', 'role' => 'super_admin',
        ]);
        $existing = Policy::query()->create([
            'title' => 'Privacy Policy', 'category' => 'privacy',
            'body' => str_repeat('A previously approved legal statement. ', 5),
            'status' => 'published',
        ]);
        $this->actingAs($admin)->post(route('dashboard.policies.supplemental-drafts'))->assertRedirect();
        $this->assertDatabaseCount('policies', 8);
        $this->assertSame(7, Policy::query()->where('status', 'draft')->count());
        $this->assertSame('published', $existing->fresh()->status);
        $one = Policy::query()->where('title', 'Payments and Billing Policy')->firstOrFail();
        $one->update(['body' => 'Preserved administrator draft edits.']);
        $this->post(route('dashboard.policies.supplemental-drafts'))->assertRedirect();
        $this->assertDatabaseCount('policies', 8);
        $this->assertSame('Preserved administrator draft edits.', $one->fresh()->body);
    }

    public function test_public_cannot_seed_or_read_review_only_drafts(): void
    {
        $this->post(route('dashboard.policies.supplemental-drafts'))->assertRedirect(route('login'));
        $this->assertDatabaseCount('policies', 0);
    }
}
