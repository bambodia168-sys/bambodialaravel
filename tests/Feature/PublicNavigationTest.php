<?php

namespace Tests\Feature;

use App\Models\MarketplaceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function category(string $type, string $name, bool $active=true): MarketplaceCategory
    {
        $editor=User::query()->first() ?? User::query()->create([
            'name'=>'Category Editor',
            'email'=>'category-editor@example.test',
            'password'=>'LongCategoryEditorPassword123!',
            'role'=>'super_admin',
        ]);
        return MarketplaceCategory::query()->create([
            'created_by'=>$editor->id,
            'type'=>$type,
            'name'=>$name,
            'description'=>'An approved marketplace navigation category for public browsing.',
            'is_active'=>$active,
            'sort_order'=>1,
        ]);
    }

    public function test_public_navigation_uses_only_real_active_categories_and_correct_type_urls(): void
    {
        $service=$this->category('service','Original Website Consulting');
        $product=$this->category('product','Professional Design Assets');
        $this->category('project','Invisible Moderated Category',false);

        $page=$this->get('/en')->assertOk()
            ->assertSee('data-public-category-menu')
            ->assertSee('Browse marketplace categories')
            ->assertSee('nav-category-groups')
            ->assertDontSee('nav-marketplace-links')
            ->assertSee('data-public-mobile-search')
            ->assertSee('nav-language-menu')
            ->assertSee('aria-label="Browse marketplace categories"',false)
            ->assertSee('Original Website Consulting')
            ->assertSee('Professional Design Assets')
            ->assertDontSee('Invisible Moderated Category')
            ->assertSee('category='.$service->public_id)
            ->assertSee('category='.$product->public_id);

        $html=$page->getContent();
        $this->assertSame(0,substr_count($html,'<x-language-menu')); // No raw Blade components.
        $this->assertSame(4,substr_count($html,'class="nav-category-group"'));
    }

    public function test_theme_stays_in_profile_and_language_moves_to_public_header(): void
    {
        $html=$this->get('/')->assertOk()
            ->assertSee('profile-theme-row')
            ->assertSee('profile-theme-toggle')
            ->assertSee('footer-social')
            ->assertSee('footer-actions')
            ->assertSee('language-menu')
            ->getContent();

        $header=explode('</header>',$html,2)[0];
        $footer=explode('<footer class="site-footer">',$html,2)[1] ?? '';
        $this->assertStringContainsString('nav-language-menu',$header);
        $this->assertStringContainsString('data-theme-toggle',$header);
        $this->assertStringNotContainsString('language-menu',$footer);
        $this->assertStringContainsString('footer-social',$footer);
        $this->assertLessThan(strpos($header,'profile-dropdown'),strpos($header,'nav-language-menu'));
        $this->assertSame(1,substr_count($html,'data-theme-toggle'));
        $this->assertSame(1,substr_count($header,'nav-language-menu'));
    }

    public function test_signed_in_profile_keeps_account_links_and_theme_control_last(): void
    {
        $user=User::query()->create([
            'name'=>'Frontend Navigation Client',
            'email'=>'frontend-navigation@example.test',
            'password'=>'AReallyLongPassword123!',
            'role'=>'customer',
        ]);
        $page=$this->actingAs($user)->get('/en')->assertOk()
            ->assertSee('Profile menu')
            ->assertSee('My Downloads')
            ->assertSee('Saved Listings')
            ->assertSee('Saved Jobs')
            ->assertSee('Sign out')
            ->assertSee('data-theme-label')
            ->assertSee('footer-actions');
        $header=explode('</header>',$page->getContent(),2)[0];
        $this->assertMatchesRegularExpression('/Sign out.*?profile-theme-row/s',$header);
    }

    public function test_active_category_urls_also_work_on_unprefixed_homepage(): void
    {
        $category=$this->category('freelance','Freelance Development');
        $this->get('/')->assertOk()
            ->assertSee('Freelance Development')
            ->assertSee('category='.$category->public_id)
            ->assertSee('aria-label="Marketplace categories"',false);
    }

    public function test_guest_account_menu_has_consistent_icon_links_and_compact_labels(): void
    {
        $html=$this->get('/en')->assertOk()
            ->assertSee('Sign In')
            ->assertSee('Sign Up')
            ->assertDontSee('Join Bambodia')
            ->assertSee('brand-logo-dark')
            ->assertSee('brand-logo-light')
            ->getContent();
        $menu=explode('<div class="dropdown-panel profile-panel">',$html,2)[1]??'';
        $this->assertMatchesRegularExpression('/<a[^>]+href="[^"]*login"[^>]*>\s*<svg /',$menu);
        $this->assertMatchesRegularExpression('/<a[^>]+href="[^"]*register"[^>]*>\s*<svg /',$menu);
        $this->assertStringContainsString('profile-theme-toggle',$menu);
    }

    public function test_signed_in_account_menu_has_an_icon_before_each_action(): void
    {
        $user=User::query()->create([
            'name'=>'Icon Menu Client',
            'email'=>'icon-menu-client@example.test',
            'password'=>'AReallyLongPassword123!',
            'role'=>'customer',
        ]);
        $html=$this->actingAs($user)->get('/en')->assertOk()->getContent();
        $menu=explode('<div class="dropdown-panel profile-panel">',$html,2)[1]??'';
        $menu=explode('<div class="profile-theme-row">',$menu,2)[0];
        $this->assertMatchesRegularExpression('/My Service Requests/',$menu);
        $this->assertMatchesRegularExpression('/<a[^>]*>\s*<svg[^>]*>.*?<\/svg>/s',$menu);
        $this->assertSame(
            preg_match_all('/<a\s[^>]*href=/',$menu),
            preg_match_all('/<a\s[^>]*href=[^>]*>\s*<svg\b/s',$menu)
        );
    }
}
