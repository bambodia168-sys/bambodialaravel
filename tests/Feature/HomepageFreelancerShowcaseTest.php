<?php

namespace Tests\Feature;

use App\Models\CreatorProfile;
use App\Models\LicenseDefinition;
use App\Models\MarketplaceListing;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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
            ->assertSee('Browse creator profiles')
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
            ->assertSee('Browse creator profiles')
            ->assertDontSee('Hidden Freelancer')
            ->assertDontSee('marketplace-freelancer-card')
            ->assertDontSee('marketplace-category-track');
    }

    public function test_public_work_from_every_listing_type_qualifies_its_creator_without_revealing_private_profile_details(): void
    {
        Storage::fake('public');

        $creators = collect([
            'product' => $this->profile('Product Creator', false),
            'project' => $this->profile('Project Publisher', false, 'super_admin'),
            'freelance' => User::query()->create([
                'name' => 'Freelance Publisher', 'role' => 'freelancer',
                'email' => 'freelance-publisher@example.test', 'password' => 'IsolatedFreelancerQaPassword123!',
            ]),
            'service' => $this->profile('Service Publisher', false, 'customer'),
        ]);
        $creators['project']->creatorProfile->update([
            'headline' => 'Unpublished freelance headline',
            'biography' => 'Unpublished freelance biography',
        ]);
        Storage::disk('public')->put('avatars/private-showcase.png', 'fixture');
        $creators['project']->userProfile()->create(['avatar_path' => 'avatars/private-showcase.png']);

        $license = LicenseDefinition::query()->create([
            'code' => 'FREELANCER_QA', 'name' => 'Showcase testing license', 'version' => '1.0.0',
            'permissions' => 'Allow QA use.', 'restrictions' => 'Prohibit redistribution.',
            'status' => 'active', 'created_by' => $creators['product']->id,
        ]);
        $cover = MediaAsset::query()->create([
            'user_id' => $creators['product']->id, 'original_name' => 'showcase.png',
            'storage_path' => 'showcase.png', 'mime_type' => 'image/png', 'bytes' => 68,
            'width' => 1, 'height' => 1, 'alt_text' => 'Showcase product cover',
            'rights_basis' => 'owned', 'rights_holder' => 'Product Creator', 'review_status' => 'approved',
        ]);
        foreach ($creators as $type => $creator) {
            MarketplaceListing::query()->create([
                'user_id' => $creator->id,
                'type' => $type,
                'title' => 'Public '.ucfirst($type).' Listing',
                'description' => 'Fixture for a public marketplace listing.',
                'status' => 'published',
                'currency' => 'USD',
                ...($type === 'product' ? ['license_definition_id' => $license->id, 'cover_media_id' => $cover->id] : []),
            ]);
        }
        MarketplaceListing::query()->create([
            'user_id' => $creators['project']->id, 'type' => 'service', 'title' => 'Second Listing',
            'description' => 'An additional public listing from the same person.', 'status' => 'published',
        ]);

        $unlisted = $this->profile('Private Publisher', false);
        foreach (['draft', 'withdrawn'] as $status) {
            MarketplaceListing::query()->create([
                'user_id' => $unlisted->id, 'type' => 'service', 'title' => ucfirst($status).' Listing',
                'description' => 'A non-public listing.', 'status' => $status,
            ]);
        }
        MarketplaceListing::query()->create([
            'user_id' => $unlisted->id, 'type' => 'product', 'title' => 'Incomplete Product',
            'description' => 'A product without an active license or cover.', 'status' => 'published',
        ]);

        foreach (['/', route('creators.index')] as $url) {
            $response = $this->get($url)->assertOk()
                ->assertDontSee($unlisted->name)
                ->assertDontSee('Unpublished freelance headline')
                ->assertDontSee('Unpublished freelance biography')
                ->assertDontSee('private-showcase.png')
                ->assertDontSee($creators['project']->email);
            $collection = $response->viewData($url === '/' ? 'freelancers' : 'creators')->getCollection();
            $this->assertEqualsCanonicalizing($creators->pluck('id')->all(), $collection->pluck('id')->all());
            $this->assertSame(2, (int) $collection->firstWhere('id', $creators['project']->id)->public_listings_count);
        }

        foreach ($creators as $type => $creator) {
            $this->get(route('creators.show', $creator->public_id))->assertOk()
                ->assertSee('Public '.ucfirst($type).' Listing')
                ->assertDontSee('Unpublished freelance headline');
        }
        $this->get(route('creators.show', $unlisted->public_id))->assertNotFound();
    }

    public function test_homepage_freelancer_pagination_reaches_the_remaining_creators(): void
    {
        foreach (range(1, 11) as $number) {
            $this->profile('Public Freelancer '.$number);
        }

        $this->get('/')->assertOk()
            ->assertSee('freelancers_page=2#bambodia-freelancers', false)
            ->assertViewHas('freelancers', fn ($people) => $people->count() === 10 && $people->total() === 11);

        $this->get('/?freelancers_page=2')->assertOk()
            ->assertViewHas('freelancers', fn ($people) => $people->count() === 1
                && $people->first()->name === 'Public Freelancer 1');
    }
}
