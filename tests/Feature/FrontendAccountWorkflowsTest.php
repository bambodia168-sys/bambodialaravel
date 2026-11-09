<?php

namespace Tests\Feature;

use App\Models\AccountNotification;
use App\Models\CreatorProfile;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\SavedListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FrontendAccountWorkflowsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role='customer'): User
    {
        return User::query()->create([
            'name'=>'Bambodia '.ucfirst($role).' '.uniqid(),
            'email'=>uniqid('account'.$role,true).'@example.test',
            'password'=>'AnExtremelySecurePassword123!',
            'role'=>$role,
        ]);
    }

    private function listing(User $owner,string $title,string $type='service',string $status='published',?string $price=null): MarketplaceListing
    {
        return MarketplaceListing::query()->create([
            'user_id'=>$owner->id,
            'title'=>$title,
            'type'=>$type,
            'status'=>$status,
            'description'=>str_repeat('An original marketplace service with professional customer deliverables and quality assurance. ',2),
            'price'=>$price,
            'currency'=>'USD',
        ]);
    }

    public function test_homepage_shows_only_eligible_public_items_and_real_approved_jobs(): void
    {
        $creator=$this->user('freelancer');
        $client=$this->user();
        $published=$this->listing($creator,'Public Original Laravel Consultation');
        $this->listing($creator,'Private Creator Pending Work','service','pending');
        $this->listing($creator,'Unlicensed Product Never Display','product','published','49.00');
        MarketplaceJob::query()->create([
            'client_id'=>$client->id,
            'title'=>'Verified Published Laravel Development Brief',
            'description'=>str_repeat('Client needs a working Laravel implementation. ',3),
            'category'=>'development',
            'status'=>'published',
        ]);
        MarketplaceJob::query()->create([
            'client_id'=>$client->id,
            'title'=>'Unreviewed Private Job Brief',
            'description'=>str_repeat('This is awaiting moderation and may not be shown publicly. ',3),
            'category'=>'development',
            'status'=>'pending',
        ]);

        $this->get('/')->assertOk()
            ->assertSee($published->title)
            ->assertSee('Verified Published Laravel Development Brief')
            ->assertDontSee('Private Creator Pending Work')
            ->assertDontSee('Unlicensed Product Never Display')
            ->assertDontSee('Unreviewed Private Job Brief')
            ->assertSee('Explore creators');
    }

    public function test_saved_listings_are_private_unique_and_role_agnostic(): void
    {
        $creator=$this->user('freelancer');
        $customer=$this->user('customer');
        $stranger=$this->user('customer');
        $listing=$this->listing($creator,'Premium Published Marketplace Service');
        $route='/account/saved-listings/'.$listing->id;

        $this->post($route)->assertRedirect('/login');
        $this->actingAs($customer)->post($route)->assertRedirect();
        $this->post($route)->assertRedirect();
        $this->assertDatabaseCount('saved_listings',1);
        $this->get('/account/saved-listings')->assertOk()->assertSee($listing->title)
            ->assertSee($listing->public_id);
        $this->assertDatabaseHas('audit_events',['event'=>'listing.saved','actor_id'=>$customer->id]);

        $this->actingAs($stranger)->get('/account/saved-listings')
            ->assertOk()->assertDontSee($listing->title);
        $this->delete($route)->assertRedirect();
        $this->assertDatabaseCount('saved_listings',1);

        $this->actingAs($customer)->delete($route)->assertRedirect();
        $this->assertDatabaseCount('saved_listings',0);
        $this->assertDatabaseHas('audit_events',['event'=>'listing.unsaved','actor_id'=>$customer->id]);
    }

    public function test_unavailable_saved_items_hide_unpublished_details_and_remain_removable(): void
    {
        $creator=$this->user('freelancer');
        $customer=$this->user();
        $listing=$this->listing($creator,'Secret Listing That Becomes Withdrawn');
        $this->actingAs($customer)->post('/account/saved-listings/'.$listing->id)->assertRedirect();

        $listing->update(['status'=>'rejected']);
        $this->get('/account/saved-listings')->assertOk()
            ->assertSee('Listing unavailable')
            ->assertDontSee('Secret Listing That Becomes Withdrawn');
        $this->get($listing->publicUrl())->assertNotFound();
        $this->post('/account/saved-listings/'.$listing->id)->assertNotFound();
        $this->delete('/account/saved-listings/'.$listing->id)->assertRedirect();
        $this->assertDatabaseCount('saved_listings',0);
    }

    public function test_every_account_role_has_its_real_private_dashboard(): void
    {
        foreach(User::ROLES as $role){
            $user=$this->user($role);
            AccountNotification::deliver($user->id,'job.reviewed','Actual account update',
                'A genuine recorded activity event for this account.','/jobs');
            $this->actingAs($user)
                ->get('/account?='.$user->public_id)
                ->assertOk()
                ->assertSee('Saved Listings')
                ->assertSee('Unread Updates')
                ->assertSee('Actual account update')
                ->assertSee($user->public_id);
        }
    }

    public function test_creator_biography_remains_private_until_explicitly_published(): void
    {
        $creator=$this->user('freelancer');
        $customer=$this->user();
        $profileFields=[
            'headline'=>'Professional Laravel Engineering and Digital Systems',
            'biography'=>str_repeat('I build original, accessible Laravel applications and provide clearly documented handovers. ',2),
            'website_url'=>'https://example.test/portfolio',
            'is_public'=>'0',
        ];
        $published=$this->listing($creator,'Public Professional Laravel Maintenance');
        $this->listing($creator,'Unapproved Confidential Website Work','service','pending');

        $this->actingAs($customer)->get('/studio/profile')->assertForbidden();
        $this->patch('/studio/profile',$profileFields)->assertForbidden();

        $this->actingAs($creator)->get('/studio/profile')->assertOk()
            ->assertSee('Creator Profile');
        $this->patch('/studio/profile',$profileFields)->assertRedirect();
        // An approved public listing permits a limited badge/work profile, but
        // never publishes the private opt-in biography or its external website.
        $this->get('/creators/'.$creator->public_id)->assertOk()
            ->assertSee($published->title)
            ->assertDontSee('Professional Laravel Engineering')
            ->assertDontSee('example.test/portfolio')
            ->assertDontSee($creator->email);
        $this->get('/creators')->assertOk()->assertDontSee('Professional Laravel Engineering');

        $this->patch('/studio/profile',[...$profileFields,'is_public'=>'1'])->assertRedirect();
        $this->get('/creators/'.$creator->public_id)->assertOk()
            ->assertSee($published->title)
            ->assertSee('Professional Laravel Engineering')
            ->assertDontSee('Unapproved Confidential Website Work')
            ->assertDontSee($creator->email);

        $this->get('/creators')->assertOk()->assertSee('Professional Laravel Engineering');
        $this->get($published->publicUrl())->assertSee('/creators/'.$creator->public_id,false);
        $this->assertDatabaseHas('audit_events',['event'=>'creator.profile.updated','actor_id'=>$creator->id]);

        $this->patch('/studio/profile',[...$profileFields,'is_public'=>'0'])->assertRedirect();
        $this->get('/creators/'.$creator->public_id)->assertOk()
            ->assertSee($published->title)
            ->assertDontSee('Professional Laravel Engineering')
            ->assertDontSee($creator->email);
        $this->get('/creators')->assertDontSee('Professional Laravel Engineering');
    }

    public function test_public_profile_cannot_leak_unapproved_products_or_private_credentials(): void
    {
        $creator=$this->user('freelancer');
        CreatorProfile::query()->create([
            'user_id'=>$creator->id,'headline'=>'Licensed and Original UI Packages',
            'biography'=>str_repeat('I build original business websites and provide licensed resources. ',2),
            'is_public'=>true,
        ]);
        $this->listing($creator,'Unapproved Public-Labeled Product','product','published','19.00');
        $this->get('/creators/'.$creator->public_id)->assertOk()
            ->assertDontSee('Unapproved Public-Labeled Product')
            ->assertDontSee($creator->email);
        $this->get('/')->assertDontSee('Unapproved Public-Labeled Product');
        $this->get('/search')->assertDontSee('Unapproved Public-Labeled Product');
    }

    public function test_public_creator_directory_limits_to_ten_and_searches_opt_in_profiles(): void
    {
        $private=$this->user('freelancer');
        CreatorProfile::query()->create([
            'user_id'=>$private->id,
            'headline'=>'Invisible Private Creator Profile',
            'biography'=>str_repeat('This profile has not been voluntarily published. ',3),
            'is_public'=>false,
        ]);

        for($i=0;$i<12;$i++){
            $user=$this->user('freelancer');
            CreatorProfile::query()->create([
                'user_id'=>$user->id,
                'headline'=>'Original Mobile App Developer '.$i,
                'biography'=>str_repeat('Experienced with original mobile and web development. ',3),
                'is_public'=>true,
            ]);
        }
        $this->get('/creators')->assertOk()
            ->assertViewHas('creators',fn($page)=>$page->count()===10 && $page->total()===12)
            ->assertDontSee('Invisible Private Creator Profile');

        $this->get('/creators?q=Mobile%20App%20Developer')->assertOk()
            ->assertViewHas('creators',fn($page)=>$page->total()===12);
        $this->get('/creators?q=Invisible%20Private')->assertOk()
            ->assertViewHas('creators',fn($page)=>$page->total()===0);
    }

    public function test_price_filter_and_sort_rely_on_actual_approved_listings(): void
    {
        $creator=$this->user('freelancer');
        $low=$this->listing($creator,'Affordable Laravel Review','service','published','25.00');
        $high=$this->listing($creator,'Enterprise Development Work','service','published','400.00');
        $this->listing($creator,'Private Confidential Review','service','pending','5.00');
        $this->listing($creator,'No Listed Budget','service','published');

        $this->get('/search?type=service&min_price=30&max_price=500&sort=price_low')
            ->assertOk()->assertSee($high->title)->assertDontSee($low->title)
            ->assertDontSee('Private Confidential Review')
            ->assertDontSee('No Listed Budget');
        $this->get('/search?sort=price_low')->assertOk()
            ->assertViewHas('listings',fn($page)=>$page->first()?->id===$low->id);
        $this->get('/search?sort=price_high')->assertOk()
            ->assertViewHas('listings',fn($page)=>$page->first()?->id===$high->id);
        $this->get('/search?sort=unexpected')->assertNotFound();
        $this->get('/search?min_price=-2')->assertNotFound();
        $this->get('/search?min_price=100&max_price=10')->assertNotFound();
    }
}
