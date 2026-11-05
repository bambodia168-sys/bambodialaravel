<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceExperienceTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role): User
    {
        return User::query()->create([
            'name'=>'Workspace '.$role,
            'email'=>uniqid('workspace',true).'@example.test',
            'password'=>'AReallyStrongPassword123!',
            'role'=>$role,
        ]);
    }

    public function test_language_prefixed_public_urls_render_and_preserve_original_routes(): void
    {
        $this->get('/login')->assertOk()->assertSee('lang="en"',false);
        $this->get('/en/login')->assertOk()
            ->assertSee('lang="en"',false)
            ->assertSee('/en/',false);
        $this->get('/km/login')->assertOk()
            ->assertSee('lang="km"',false)
            ->assertSee('ខ្មែរ');
        $this->get('/zh/register')->assertOk()->assertSee('lang="zh"',false);
        $this->get('/de/search')->assertOk()->assertSee('lang="de"',false);
        $this->get('/xx/login')->assertNotFound();
    }

    public function test_locale_switch_preserves_current_page_and_query_identifiers(): void
    {
        $this->from('/en/search?q=laravel&type=service')
            ->post('/en/locale',['locale'=>'km'])
            ->assertRedirect(url('/km/search?q=laravel&type=service'));

        $this->from('/en/login')
            ->post('/locale',['locale'=>'de'])
            ->assertRedirect(url('/de/login'));

        $this->from('/km/search?q=abc')
            ->post('/locale',['locale'=>'unsupported'])
            ->assertSessionHasErrors('locale');

        $this->from('https://another.example.test/bad')
            ->post('/locale',['locale'=>'en'])
            ->assertRedirect(url('/en'));
    }

    public function test_admin_workspace_has_admin_only_nav_and_independently_scrolling_shell(): void
    {
        $admin=$this->account('super_admin');
        $this->actingAs($admin)->get('/en/dashboard')
            ->assertOk()
            ->assertSee('data-workspace="admin"',false)
            ->assertSee('workspace-sidebar')
            ->assertSee('workspace-menu-search')
            ->assertSee('data-workspace-toggle')
            ->assertSee('workspace-home-link')
            ->assertSee('Download Entitlements')
            ->assertSee('User Manager')
            ->assertSee('workspace-nav-link')
            ->assertDontSee('Bambodia<span class="brand-dot">.',false);
    }

    public function test_creator_workspace_is_distinct_with_real_management_links(): void
    {
        $creator=$this->account('freelancer');
        $this->actingAs($creator)->get('/en/creator')
            ->assertOk()->assertSee('data-workspace="creator"',false)
            ->assertSee('Product Releases')
            ->assertSee('Media Library')
            ->assertSee('Client Inquiries')
            ->assertDontSee('User Manager');

        $this->get('/studio?='.$creator->public_id)->assertOk()
            ->assertSee('Creator Studio');
    }

    public function test_account_workspace_contains_personal_routes_but_not_super_admin_tools(): void
    {
        $client=$this->account('customer');
        $this->actingAs($client)->get('/en/account?='.$client->public_id)
            ->assertOk()
            ->assertSee('data-workspace="account"',false)
            ->assertSee('Saved Listings')
            ->assertSee('Saved Jobs')
            ->assertSee('Privacy Requests')
            ->assertDontSee('API Credentials')
            ->assertDontSee('User Manager');

        $this->get('/en/dashboard')->assertForbidden();
        $this->get('/en/developer')->assertForbidden();
    }

    public function test_developer_workspace_is_separate_and_requires_super_admin(): void
    {
        $admin=$this->account('super_admin');
        $this->actingAs($admin)->get('/en/developer')
            ->assertOk()->assertSee('data-workspace="developer"',false)
            ->assertSee('API Credentials')
            ->assertSee('API Documentation')
            ->assertDontSee('Digital File Vault');

        $this->get('/en/developer/docs')->assertOk()->assertSee('data-workspace="developer"',false);
        $this->actingAs($this->account('admin'))->get('/en/developer')->assertForbidden();
    }

    public function test_roles_cannot_enter_creator_workspace_without_approval(): void
    {
        $client=$this->account('customer');
        $this->actingAs($client)->get('/en/creator')
            ->assertRedirect(route('creator.apply'));
        $staff=$this->account('staff');
        $this->actingAs($staff)->get('/en/creator')
            ->assertRedirect('/account?='.$staff->public_id);
    }
}
