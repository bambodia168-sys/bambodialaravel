<?php

namespace Tests\Feature;

use App\Models\Policy;
use App\Models\PolicyPublication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPolicyReadingLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_policy_sidebar_and_document_actions_render_in_requested_order(): void
    {
        $admin = User::query()->create([
            'name' => 'Legal Reviewer',
            'email' => 'legal-reviewer@example.test',
            'password' => 'StrongPassword123!',
            'role' => 'super_admin',
        ]);
        $policy = Policy::query()->create([
            'title' => 'Regular License',
            'body' => "# Regular License\n\n## Grant and scope\n".str_repeat('A documented licensed usage condition applies. ', 3),
            'body_format' => 'markdown',
            'category' => 'commerce',
            'status' => 'draft',
            'version' => '1.0.0',
            'pic_email' => 'legal@bambodia.com',
        ]);
        $this->actingAs($admin)->patch(route('dashboard.policies.update', $policy), [
            'title' => $policy->title,
            'body' => $policy->body,
            'body_format' => 'markdown',
            'category' => 'commerce',
            'status' => 'published',
            'version' => '1.0.0',
            'confirm_legal_review' => '1',
        ])->assertRedirect();
        $policy->refresh();

        $response = $this->get($policy->publicUrl());
        $response->assertOk()
            ->assertSee('All policies')
            ->assertSee('Policy ID')
            ->assertSee($policy->public_code)
            ->assertSee('Commerce')
            ->assertSee('v1.0.0')
            ->assertSee('Full URL')
            ->assertSee('Print')
            ->assertSee('Share')
            ->assertSee('Policy directory')
            ->assertDontSee('Bambodia · Legal policy')
            ->assertDontSee('Copy full URL')
            ->assertDontSee('Publication SHA-256');

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'class="policy-back policy-sidebar-back"'));
        $this->assertSame(1, substr_count($html, 'class="policy-document-header"'));
        $this->assertSame(1, substr_count($html, 'class="policy-document-footer"'));
        $this->assertSame(1, substr_count($html, 'data-policy-copy-url='));
        $this->assertLessThan(strpos($html, 'class="policy-document-header"'), strpos($html, 'class="policy-back policy-sidebar-back"'));
        $this->assertLessThan(strpos($html, 'class="policy-copy"'), strpos($html, 'class="policy-document-header"'));
        $this->assertLessThan(strpos($html, 'class="policy-document-footer"'), strpos($html, 'class="policy-copy"'));

        $publication = PolicyPublication::query()->where('policy_id', $policy->id)->firstOrFail();
        $this->assertNotEmpty($publication->sha256);
        $this->assertStringNotContainsString($publication->sha256, $html);
    }

    public function test_guest_cannot_see_unpublished_policy(): void
    {
        $policy = Policy::query()->create([
            'title' => 'Unpublished Policy',
            'body' => str_repeat('Example policy requiring legal approval. ', 3),
            'category' => 'terms',
            'status' => 'draft',
        ]);
        $this->get($policy->publicUrl())->assertNotFound();
    }
}
