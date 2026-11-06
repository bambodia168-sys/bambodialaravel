<?php

namespace Tests\Feature;

use App\Models\MarketplaceCategory;
use App\Models\MarketplaceListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::query()->create([
            'name'=>'Category '.$role,
            'email'=>uniqid($role,true).'@example.test',
            'password'=>'AccountPassword123!',
            'role'=>$role,
        ]);
    }

    private function createCategory(User $admin,string $type,string $name): MarketplaceCategory
    {
        $this->actingAs($admin)->post('/dashboard/category-manager',[
            'type'=>$type,'name'=>$name,
            'description'=>'Operational marketplace category for approved creator listings.',
            'sort_order'=>'1','is_active'=>'1',
        ])->assertRedirect();
        return MarketplaceCategory::query()->where('name',$name)->firstOrFail();
    }

    public function test_admin_can_create_edit_and_deactivate_categories_with_unique_public_ids(): void
    {
        $admin=$this->user('admin');
        $category=$this->createCategory($admin,'service','Web Development');

        $this->assertMatchesRegularExpression('/^[0-9]{9}$/',$category->public_id);
        $this->assertSame('web-development',$category->slug);
        $this->get('/dashboard/category-manager')->assertOk()->assertSee('Web Development');
        $this->patch('/dashboard/category-manager/'.$category->id,[
            'type'=>'service','name'=>'Application Development','description'=>'Updated category for genuine service listings.',
            'sort_order'=>'2','is_active'=>'0',
        ])->assertRedirect();
        $this->assertFalse($category->fresh()->is_active);
        $this->assertSame('application-development',$category->fresh()->slug);
        $this->assertDatabaseHas('audit_events',['event'=>'category.updated','actor_id'=>$admin->id]);
    }

    public function test_listing_submission_requires_matching_active_category_when_provided(): void
    {
        $admin=$this->user('admin');
        $creator=$this->user('freelancer');
        $productCategory=$this->createCategory($admin,'product','Templates');
        $serviceCategory=$this->createCategory($admin,'service','Custom Development');
        $details=[
            'type'=>'service',
            'title'=>'Original Enterprise Laravel Website',
            'description'=>str_repeat('A professionally delivered original custom service. ',2),
        ];
        $this->actingAs($creator)->post('/studio/listings',[
            ...$details,'category_id'=>$productCategory->id,
        ])->assertSessionHasErrors('category_id');
        $this->assertDatabaseCount('marketplace_listings',0);

        $this->post('/studio/listings',[
            ...$details,'category_id'=>$serviceCategory->id,
        ])->assertRedirect();
        $listing=MarketplaceListing::firstOrFail();
        $this->assertSame('pending',$listing->status);
        $this->assertSame($serviceCategory->id,$listing->category_id);
    }

    public function test_public_search_filters_by_active_category_and_shows_only_published(): void
    {
        $admin=$this->user('admin');
        $creator=$this->user('freelancer');
        $selected=$this->createCategory($admin,'service','Technical Consulting');
        $other=$this->createCategory($admin,'service','Graphic Design');

        MarketplaceListing::query()->create([
            'user_id'=>$creator->id,'type'=>'service','title'=>'Security Review Consultation',
            'description'=>str_repeat('Expert analysis and actual client services. ',2),
            'category_id'=>$selected->id,'status'=>'published',
        ]);
        MarketplaceListing::query()->create([
            'user_id'=>$creator->id,'type'=>'service','title'=>'Logo and Artwork Design',
            'description'=>str_repeat('Original creative graphic design services. ',2),
            'category_id'=>$other->id,'status'=>'published',
        ]);
        $this->get('/search?type=service&category='.$selected->public_id)
            ->assertOk()->assertSee('Security Review Consultation')->assertDontSee('Logo and Artwork Design');

        $this->actingAs($admin)->patch('/dashboard/category-manager/'.$selected->id,[
            'type'=>'service','name'=>'Technical Consulting','sort_order'=>'1','is_active'=>'0',
        ])->assertRedirect();
        $this->get('/search?type=service&category='.$selected->public_id)
            ->assertDontSee('Security Review Consultation');
    }

    public function test_referenced_category_cannot_change_marketplace_type_and_rejected_owner_can_resubmit(): void
    {
        $admin=$this->user('admin');
        $creator=$this->user('freelancer');
        $category=$this->createCategory($admin,'project','Laravel Project');
        $listing=MarketplaceListing::query()->create([
            'user_id'=>$creator->id,'type'=>'project','title'=>'Initial web development brief',
            'description'=>str_repeat('A clearly scoped project with real deliverables. ',2),
            'category_id'=>$category->id,'status'=>'rejected',
        ]);

        $this->actingAs($admin)->patch('/dashboard/category-manager/'.$category->id,[
            'type'=>'service','name'=>'Laravel Project','sort_order'=>'1','is_active'=>'1',
        ])->assertStatus(409);

        $this->actingAs($creator)->patch('/studio/listings/'.$listing->id,[
            'title'=>'Revised Web Development Project',
            'description'=>str_repeat('Revised detailed scope with original project deliverables. ',2),
            'category_id'=>$category->id,
        ])->assertRedirect();
        $this->assertSame('pending',$listing->fresh()->status);
        $this->assertDatabaseHas('audit_events',['event'=>'listing.resubmitted','actor_id'=>$creator->id]);

        $stranger=$this->user('freelancer');
        $this->actingAs($stranger)->patch('/studio/listings/'.$listing->id,[
            'title'=>'Unauthorized update of project',
            'description'=>str_repeat('Unauthorized content should not update another owner. ',2),
        ])->assertNotFound();
    }

    public function test_only_administrators_can_change_categories_and_lists_paginate_ten(): void
    {
        $admin=$this->user('admin');
        $customer=$this->user('customer');
        for($i=0;$i<13;$i++){
            $this->createCategory($admin,'service','Service Category '.$i);
        }
        $this->actingAs($customer)->get('/dashboard/category-manager')->assertForbidden();
        $this->post('/dashboard/category-manager',[
            'type'=>'service','name'=>'Untrusted Category',
        ])->assertForbidden();
        $this->actingAs($admin)->get('/dashboard/category-manager')
            ->assertOk()->assertViewHas('categories',fn($page)=>$page->count()===10 && $page->total()===13);
    }
    public function test_search_sidebar_remains_functional_without_duplicate_listing_tabs(): void
    {
        $admin=$this->user('admin');
        $this->createCategory($admin,'service','Technical Services');
        $product=$this->createCategory($admin,'product','Licensed Templates');

        $response=$this->get('/search?type=service&q=website&sort=title');
        $response->assertOk()
            ->assertDontSee('marketplace-discovery-tabs')
            ->assertDontSee('aria-label="Marketplace listing types"',false)
            ->assertSee('id="marketplace-type"',false)
            ->assertSee('id="marketplace-category"',false)
            ->assertSee('Apply</button>',false)
            ->assertDontSee('Apply filters')
            ->assertSee('Technical Services')
            ->assertSee('Active filters')
            ->assertSee('Keyword: website')
            ->assertSee('Type: Service')
            ->assertSee('Clear all');

        $this->assertStringContainsString('q=website', $response->getContent());
        $this->assertStringContainsString('sort=title', $response->getContent());
        $this->assertStringNotContainsString('<option value="'.$product->public_id.'"', $response->getContent());

        $productResponse=$this->get('/search?type=product&category='.$product->public_id);
        $productResponse->assertOk()->assertSee('Category: Licensed Templates');
        $this->assertStringContainsString('<option value="'.$product->public_id.'"', $productResponse->getContent());
        $this->get('/search')->assertOk()->assertDontSee('marketplace-active-filters')
            ->assertDontSee('marketplace-discovery-tabs')
            ->assertSee('Technical Services')->assertSee('Licensed Templates');
        foreach (['product', 'project', 'service', 'freelance'] as $type) {
            $this->get('/search?type='.$type)->assertOk()
                ->assertDontSee('marketplace-discovery-tabs')
                ->assertSee('id="marketplace-type"', false)
                ->assertSee('Apply</button>', false);
        }
    }

}
