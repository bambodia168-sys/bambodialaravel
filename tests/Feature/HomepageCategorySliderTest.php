<?php

namespace Tests\Feature;

use App\Models\MarketplaceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageCategorySliderTest extends TestCase
{
    use RefreshDatabase;

    public function test_slider_keeps_every_active_category_and_its_real_search_link(): void
    {
        $owner=User::query()->create([
            'name'=>'Category Rail QA Owner',
            'email'=>'category-rail-qa@example.test',
            'password'=>'OnlyForIsolatedQaTesting123',
            'role'=>'freelancer',
        ]);
        $all=[];
        foreach (range(1,13) as $n) {
            $type=['product','project','service','freelance'][($n-1) % 4];
            $all[]=MarketplaceCategory::query()->create([
                'type'=>$type,
                'name'=>"Layout Slider Category $n",
                'description'=>"Category $n allows testing every card in a complete horizontal rail.",
                'sort_order'=>$n,
                'is_active'=>true,
                'created_by'=>$owner->id,
            ]);
        }
        $hidden=MarketplaceCategory::query()->create([
            'type'=>'service',
            'name'=>'Hidden Slider Category',
            'is_active'=>false,
            'sort_order'=>100,
            'created_by'=>$owner->id,
        ]);

        $page=$this->get('/')->assertOk()
            ->assertSee('id="marketplace-category-track"',false)
            ->assertSee('data-category-slider',false)
            ->assertSee('data-category-next',false)
            ->assertSee('data-category-prev',false)
            ->assertSee('--category-total: 13;',false)
            ->assertDontSee($hidden->name);

        $html=$page->getContent();
        $this->assertSame(13,substr_count($html,'class="marketplace-category-tile"'));
        foreach ($all as $category) {
            $page->assertSee($category->name);
            $page->assertSee(
                e(route('marketplace.search',[
                    'type'=>$category->type,
                    'category'=>$category->public_id,
                ])),
                false
            );
        }
    }

    public function test_category_tile_uses_accessible_vertical_link_not_nested_actions(): void
    {
        $owner=User::query()->create([
            'name'=>'Category QA Owner','email'=>'category-owner@example.test',
            'password'=>'OnlyForIsolatedQaTesting123','role'=>'freelancer',
        ]);
        MarketplaceCategory::query()->create([
            'type'=>'freelance',
            'name'=>'Original Freelance Professionals',
            'description'=>'Independent professional specialist categories.',
            'is_active'=>true,
            'sort_order'=>1,
            'created_by'=>$owner->id,
        ]);
        $this->get('/')->assertOk()
            ->assertSee('marketplace-category-icon')
            ->assertSee('marketplace-category-type')
            ->assertSee('marketplace-category-name')
            ->assertSee('marketplace-category-description')
            ->assertSee('marketplace-category-footer')
            ->assertSee('aria-label="All active marketplace categories"',false)
            ->assertSee('Original Freelance Professionals');
    }
}
