<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role): User
    {
        return User::query()->create([
            'name'=>'Site Settings '.$role,
            'email'=>uniqid($role,true).'@example.test',
            'password'=>'SecureAccountPassword123!',
            'role'=>$role,
        ]);
    }

    public function test_appearance_editor_changes_real_homepage_content(): void
    {
        $admin=$this->account('admin');
        $this->actingAs($admin)->get('/dashboard/appearance')->assertOk()->assertSee('Homepage headline');
        $this->patch('/dashboard/appearance',[
            'key'=>'appearance.hero_title_en','value'=>'Original creative marketplace for global customers',
        ])->assertRedirect();

        $this->get('/')->assertOk()->assertSee('Original creative marketplace for global customers');
        $this->assertSame('Original creative marketplace for global customers',SiteSetting::setting('appearance.hero_title_en'));
        $this->assertDatabaseHas('audit_events',['event'=>'site.setting.changed','actor_id'=>$admin->id]);
    }

    public function test_appearance_visibility_controls_real_home_sections(): void
    {
        $admin=$this->account('admin');
        $this->actingAs($admin)->patch('/dashboard/appearance',[
            'key'=>'appearance.hero_search_enabled','value'=>'0',
        ])->assertRedirect();
        $this->patch('/dashboard/appearance',[
            'key'=>'appearance.hero_categories_enabled','value'=>'0',
        ])->assertRedirect();

        $this->get('/')->assertOk()->assertDontSee('class="hero-search"',false)
            ->assertDontSee('class="hero-links"',false);
        $this->get('/search')->assertOk();
    }

    public function test_system_settings_change_theme_and_official_support_contact(): void
    {
        $admin=$this->account('super_admin');
        $this->actingAs($admin)->get('/dashboard/system-settings')->assertOk()->assertSee('Default display theme');

        $this->patch('/dashboard/system-settings',[
            'key'=>'site.default_theme','value'=>'dark',
        ])->assertRedirect();
        $this->patch('/dashboard/system-settings',[
            'key'=>'site.support_email','value'=>'help@example.org',
        ])->assertRedirect();

        $this->get('/')->assertOk()->assertSee('mailto:help@example.org')
            ->assertSee('"dark"',false);
    }

    public function test_unauthorized_and_invalid_settings_are_rejected(): void
    {
        $customer=$this->account('customer');
        $this->actingAs($customer)->get('/dashboard/appearance')->assertForbidden();
        $this->patch('/dashboard/system-settings',[
            'key'=>'site.default_theme','value'=>'dark',
        ])->assertForbidden();

        $admin=$this->account('admin');
        $this->actingAs($admin)->patch('/dashboard/system-settings',[
            'key'=>'site.default_theme','value'=>'untrusted',
        ])->assertSessionHasErrors('value');
        $this->patch('/dashboard/appearance',[
            'key'=>'site.default_theme','value'=>'dark',
        ])->assertForbidden();
        $this->patch('/dashboard/system-settings',[
            'key'=>'site.support_email','value'=>'not-an-email',
        ])->assertSessionHasErrors('value');
    }
}
