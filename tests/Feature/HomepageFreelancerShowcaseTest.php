<?php

namespace Tests\Feature;

use App\Models\CreatorProfile;
use App\Models\MarketplaceListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageFreelancerShowcaseTest extends TestCase
{
    use RefreshDatabase;

    private function profile(string $name,bool $public=true,string $role='freelancer'): User
    {
        $user=User::query()->create([
            'name'=>$name,
            'email'=>uniqid('freelancer-',true).'@example.test',
            'password'=>'IsolatedFreelancerQaPassword123!',
            'role'=>$role,
        ]);
        CreatorProfile::query()->create([
            'user_id'=>$user->id,
            'headline'=>'Professional independent digital work',
            'biography'=>str_repeat('Realistic isolated profile content for testing. ',4),
            'is_public'=>$public,
        ]);
        return $user;
    }

    public function test_homepage_prioritizes_public_freelancers_with_approved_work_and_shows_new_freelancers(): void
    {
        $experienced=$this->profile('Experienced Public Freelancer');
        $new=$this->profile('New Public Freelancer');
        $this->profile('Private Freelancer',false);
        $this->profile('Administrator Account',true,'super_admin');
        $this->profile('Customer Account',true,'customer');

        MarketplaceListing::query()->create([
            'user_id'=>$experienced->id,'type'=>'service',
            'title'=>'Approved Professional Service',
            'description'=>str_repeat('Published services for independent acceptance. ',3),
            'status'=>'published','price'=>'50.00','currency'=>'USD',
        ]);
        MarketplaceListing::query()->create([
            'user_id'=>$new->id,'type'=>'service',
            'title'=>'Pending Service',
            'description'=>str_repeat('An unpublished offer with no public activity. ',3),
            'status'=>'draft','price'=>'60.00','currency'=>'USD',
        ]);

        $response=$this->get('/')->assertOk()
            ->assertSee('id="bambodia-freelancers"',false)
            ->assertSee('id="freelancer-section-title">Freelancer</h2>',false)
            ->assertSee('View all freelancers')
            ->assertSee('Experienced Public Freelancer')
            ->assertSee('New Public Freelancer')
            ->assertDontSee('Private Freelancer')
            ->assertDontSee('Administrator Account')
            ->assertDontSee('Customer Account')
            ->assertDontSee('marketplace-category-track')
            ->assertDontSee('Top Rated')
            ->assertSee(e(route('creators.show',$experienced->public_id)),false)
            ->assertSee(e(route('creators.show',$new->public_id)),false);

        $response->assertViewHas('freelancers',function($people)use($experienced,$new){
            return $people->count()===2 &&
                $people->first()->id===$experienced->id &&
                $people->last()->id===$new->id &&
                (int)$people->first()->public_listings_count===1 &&
                (int)$people->last()->public_listings_count===0;
        });
    }

    public function test_freelancer_section_has_a_useful_empty_state_without_fake_profiles(): void
    {
        $this->profile('Hidden Freelancer',false);
        $this->get('/')->assertOk()
            ->assertSee('Freelancer profiles are coming soon.')
            ->assertSee('Browse the freelancer directory')
            ->assertDontSee('Hidden Freelancer')
            ->assertDontSee('marketplace-freelancer-card')
            ->assertDontSee('marketplace-category-track');
    }
}
