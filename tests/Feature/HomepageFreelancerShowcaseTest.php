<?php

namespace Tests\Feature;

use App\Models\CreatorProfile;
use App\Models\CreatorProfileItem;
use App\Models\CreatorProfileSection;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceListing;
use App\Models\User;
use App\Models\UserProfile;
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
            ->assertSee('id="freelancer-section-title">Freelancers</h2>',false)
            ->assertSee('Browse freelancers')
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

    public function test_single_card_uses_only_public_expertise_and_links_to_published_work(): void
    {
        $creator = $this->profile('Portrait Example');
        $creator->creatorProfile->update(['headline' => 'Full-stack developer | Platform specialist']);
        UserProfile::query()->create([
            'user_id' => $creator->id,
            'avatar_path' => 'profile-avatars/example.webp',
            'public_location' => 'Legacy district, Battambang, Cambodia',
        ]);
        foreach (['Laravel', 'PHP', 'WordPress', 'Fourth skill'] as $index => $label) {
            CreatorProfileItem::query()->create([
                'user_id' => $creator->id, 'kind' => 'expertise', 'label' => $label,
                'sort_order' => $index, 'is_visible' => true,
            ]);
        }
        CreatorProfileItem::query()->create([
            'user_id' => $creator->id, 'kind' => 'expertise', 'label' => 'Private Expertise',
            'sort_order' => 0, 'is_visible' => false,
        ]);
        $profileUrl = route('creators.show', $creator->public_id);

        $this->get('/')->assertOk()
            ->assertSee('freelancer-showcase-grid--single')
            ->assertSee('freelancer-discovery.css')
            ->assertSee('profile-avatars/example.webp')
            ->assertDontSee('freelancer-showcase-profile')
            ->assertSee($profileUrl.'#published-work')
            ->assertSee('title="0 approved listings" aria-label="0 approved listings">0</a>', false)
            ->assertSeeInOrder(['class="freelancer-showcase-footer"', '>0</a>', 'Battambang, Cambodia'], false)
            ->assertDontSee('Legacy district')
            ->assertSee('Full-stack developer')
            ->assertDontSee('Platform specialist')
            ->assertSeeInOrder(['<li>Laravel</li>', '<li>PHP</li>', '<li>WordPress</li>'], false)
            ->assertDontSee('Fourth skill')
            ->assertDontSee('Private Expertise');
        $this->get($profileUrl)->assertOk()->assertSee('id="published-work"', false);
    }

    public function test_directory_filters_match_public_creator_data_and_published_categories(): void
    {
        $match = $this->profile('Searchable Developer');
        $hidden = $this->profile('Hidden Skill Developer');
        $categories = [];
        foreach (['Public design', 'Unpublished design'] as $name) {
            $categories[] = MarketplaceCategory::query()->create(['type' => 'service', 'name' => $name, 'is_active' => true, 'created_by' => $match->id]);
        }
        foreach ([$match, $hidden] as $index => $person) {
            UserProfile::query()->create(['user_id' => $person->id, 'public_location' => 'Battambang']);
            CreatorProfileItem::query()->create([
                'user_id' => $person->id, 'kind' => 'expertise', 'label' => 'Specialty Laravel',
                'sort_order' => 10, 'is_visible' => true,
            ]);
            MarketplaceListing::query()->create([
                'user_id' => $person->id, 'category_id' => $categories[$index]->id, 'type' => 'service',
                'title' => 'Independent design', 'description' => 'Directory category fixture.',
                'status' => $index === 0 ? 'published' : 'draft', 'price' => '50.00', 'currency' => 'USD',
            ]);
        }
        CreatorProfileSection::query()->create([
            'user_id' => $hidden->id, 'section_key' => 'expertise', 'title' => 'Expertise',
            'sort_order' => 10, 'is_visible' => false,
        ]);
        $this->get('/creators?q=Specialty+Laravel')->assertOk()
            ->assertSee('Searchable Developer')->assertDontSee('Hidden Skill Developer');
        $this->get('/creators?skill=Laravel&location=Battambang&category='.$categories[0]->public_id)->assertOk()
            ->assertSee('Searchable Developer')->assertDontSee('Hidden Skill Developer')
            ->assertSee('<option value="'.$categories[0]->public_id.'" selected>', false)
            ->assertDontSee('<option value="'.$categories[1]->public_id.'"', false)
            ->assertSee('Clear filters');
        $this->get('/creators?category='.$categories[1]->public_id)->assertOk()
            ->assertSee('No public creator profiles match your search.');
        $this->get('/creators?skill=Laravel&location=Elsewhere')->assertOk()
            ->assertSee('No public creator profiles match your search.');
    }

    public function test_freelancer_section_has_a_useful_empty_state_without_fake_profiles(): void
    {
        $this->profile('Hidden Freelancer',false);
        $this->get('/')->assertOk()
            ->assertSee('Freelancer profiles are coming soon.')
            ->assertSee('Browse freelancers')
            ->assertDontSee('Hidden Freelancer')
            ->assertDontSee('freelancer-showcase-card')
            ->assertDontSee('marketplace-category-track');
    }
}
