<?php

namespace Tests\Feature;

use App\Models\Policy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharedRowActionMenuTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::query()->create([
            'name' => 'Action Menu Admin',
            'email' => 'action-admin@example.test',
            'password' => 'test-only-password',
            'role' => 'super_admin',
        ]);
    }

    public function test_policy_rows_use_a_single_semantic_three_dot_menu_with_correct_routes(): void
    {
        $policy = Policy::query()->create([
            'title' => 'Action Menu Test Terms',
            'category' => 'terms',
            'body' => str_repeat('Previously reviewed sample terms for private QA. ', 5),
            'status' => 'draft',
            'body_format' => 'markdown',
        ]);
        $this->actingAs($this->superAdmin());
        $page = $this->get(route('dashboard.policies'))->assertOk();

        $page->assertSee('id="row-action-trigger-policy-'.$policy->id.'"', false);
        $page->assertSee('id="row-action-menu-policy-'.$policy->id.'"', false);
        $page->assertSee('data-row-action-menu', false);
        $page->assertSee('popover="auto"', false);
        $page->assertSee('aria-haspopup="menu"', false);
        $page->assertSee(route('dashboard.policies.edit', $policy), false);
        $page->assertSee(route('dashboard.policies.preview', $policy), false);

        $html = $page->getContent();
        $this->assertSame(1, substr_count($html, 'id="row-action-trigger-policy-'.$policy->id.'"'));
        $this->assertSame(1, substr_count($html, 'id="row-action-menu-policy-'.$policy->id.'"'));
        $this->assertStringContainsString('aria-label="Actions for Action Menu Test Terms"', $html);
    }

    public function test_shared_component_has_accessible_trigger_and_native_menu_markup(): void
    {
        $html = view('components.row-action-menu', [
            'id' => 'fixture-21',
            'label' => 'Example Record',
            'slot' => new \Illuminate\Support\HtmlString('<a href="/allowed">Edit</a>'),
        ])->render();

        $this->assertStringContainsString('id="row-action-trigger-fixture-21"', $html);
        $this->assertStringContainsString('popovertarget="row-action-menu-fixture-21"', $html);
        $this->assertStringContainsString('id="row-action-menu-fixture-21"', $html);
        $this->assertStringContainsString('role="menu"', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertStringContainsString('aria-label="Actions for Example Record"', $html);
        $this->assertStringContainsString('<a href="/allowed">Edit</a>', $html);
    }

    public function test_row_menus_share_one_component_across_existing_action_tables(): void
    {
        $required = [
            'dashboard/policies', 'dashboard/knowledge-index', 'dashboard/jobs',
            'dashboard/products', 'dashboard/reviews', 'dashboard/users',
            'dashboard/contracts', 'dashboard/contract-disputes', 'dashboard/support',
            'dashboard/marketplace', 'dashboard/creator-applications',
            'dashboard/categories', 'dashboard/features', 'dashboard/entitlements',
            'dashboard/privacy-requests', 'dashboard/vault-versions',
            'dashboard/contents', 'dashboard/site-settings', 'dashboard/licenses',
            'account/products', 'account/listings', 'account/licenses',
            'account/downloads', 'account/notifications', 'account/privacy-requests',
            'account/product-versions', 'account/saved-jobs', 'account/saved-listings',
            'developer/index', 'media/index', 'jobs/mine', 'jobs/proposals',
            'contracts/index', 'inquiries/index',
        ];
        foreach ($required as $path) {
            $source = file_get_contents(resource_path('views/'.$path.'.blade.php'));
            $this->assertIsString($source, $path);
            $this->assertStringContainsString('<x-row-action-menu', $source, $path);
        }

        $source = file_get_contents(resource_path('views/dashboard/privacy-requests.blade.php'));
        $this->assertStringContainsString('Self-review prohibited', $source);
        $source = file_get_contents(resource_path('views/dashboard/contract-disputes.blade.php'));
        $this->assertStringContainsString('Conflict of interest', $source);
        $source = file_get_contents(resource_path('views/dashboard/licenses.blade.php'));
        $this->assertStringContainsString('data-license-focus', $source);
        $this->assertStringContainsString('data-row-action-menu', $source);
    }
}
