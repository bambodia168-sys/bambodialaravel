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
        return MarketplaceCategory::query()->create([
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
            ->assertSee('nav-marketplace-links')
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

    public function test_theme_is_only_in_profile_and_language_only_in_footer_after_social_links(): void
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
        $this->assertStringNotContainsString('language-menu',$header);
        $this->assertStringContainsString('data-theme-toggle',$header);
        $this->assertLessThan(strpos($footer,'language-menu'),strpos($footer,'footer-social'));
        $this->assertSame(1,substr_count($html,'data-theme-toggle'));
        $this->assertSame(1,substr_count($html,'class="language-menu'));
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
            ->assertSee('aria-label="Marketplace"',false);
    }
}
