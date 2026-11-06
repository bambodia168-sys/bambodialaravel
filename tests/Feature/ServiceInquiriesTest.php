<?php

namespace Tests\Feature;

use App\Models\MarketplaceListing;
use App\Models\ServiceInquiry;
use App\Models\ServiceInquiryMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceInquiriesTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::query()->create([
            'name'=>'Private '.$role,
            'email'=>uniqid($role,true).'@example.test',
            'password'=>'SuperSecureAccount123!',
            'role'=>$role,
        ]);
    }

    private function listing(User $creator, string $type='service', string $status='published'): MarketplaceListing
    {
        return MarketplaceListing::query()->create([
            'user_id'=>$creator->id,
            'type'=>$type,
            'title'=>'Professional Laravel Developer Service',
            'description'=>str_repeat('Original professional services with a defined scope. ',2),
            'status'=>$status,
            'currency'=>'USD',
        ]);
    }

    private function send(User $client, MarketplaceListing $listing): ServiceInquiry
    {
        $this->actingAs($client)->post('/inquiries/for/'.$listing->id,[
            'subject'=>'Please estimate my original Laravel website',
            'body'=>str_repeat('We need an accessible, secure marketplace with clear milestones. ',2),
            'proposed_budget'=>'500.00',
        ])->assertRedirect();

        return ServiceInquiry::query()->latest('id')->firstOrFail();
    }

    public function test_client_inquiry_is_private_and_has_nine_digit_reference(): void
    {
        $client=$this->user('customer');
        $creator=$this->user('freelancer');
        $other=$this->user('customer');
        $listing=$this->listing($creator);
        $inquiry=$this->send($client,$listing);

        $this->assertMatchesRegularExpression('/^[0-9]{9}$/', $inquiry->public_id);
        $this->assertSame($listing->id,$inquiry->marketplace_listing_id);
        $this->assertDatabaseCount('service_inquiry_messages',1);

        $this->actingAs($other)->get('/inquiries/'.$inquiry->id)->assertNotFound();
        $this->post('/inquiries/'.$inquiry->id.'/reply',['body'=>'I am not a participant'])->assertNotFound();
        $this->patch('/inquiries/'.$inquiry->id.'/close')->assertNotFound();
        $this->get('/account/inquiries')->assertDontSee($inquiry->subject);

        $this->actingAs($creator)->get('/studio/inquiries')->assertOk()->assertSee($inquiry->subject);
        $this->get('/inquiries/'.$inquiry->id)->assertOk()->assertSee('We need an accessible');
        $this->assertDatabaseHas('audit_events',['event'=>'inquiry.submitted','actor_id'=>$client->id]);
    }

    public function test_participants_can_reply_and_close_but_closed_inquiries_reject_messages(): void
    {
        $client=$this->user('subscriber');
        $creator=$this->user('freelancer');
        $inquiry=$this->send($client,$this->listing($creator,'project'));

        $this->actingAs($creator)->post('/inquiries/'.$inquiry->id.'/reply',[
            'body'=>'I can provide a documented project proposal.',
        ])->assertRedirect();
        $this->assertDatabaseCount('service_inquiry_messages',2);
        $this->assertDatabaseHas('audit_events',[
            'actor_id'=>$creator->id, 'event'=>'inquiry.replied',
        ]);
        $this->assertDatabaseHas('account_notifications',[
            'user_id'=>$client->id, 'event'=>'inquiry.replied',
        ]);
        $this->actingAs($client)->get('/inquiries/'.$inquiry->id)
            ->assertOk()->assertSee('I can provide a documented project proposal.');
        $this->patch('/inquiries/'.$inquiry->id.'/close')->assertRedirect();
        $this->assertSame('closed',$inquiry->fresh()->status);
        $this->assertDatabaseHas('account_notifications',[
            'user_id'=>$creator->id, 'event'=>'inquiry.closed',
        ]);
        $this->assertDatabaseHas('audit_events',[
            'actor_id'=>$client->id, 'event'=>'inquiry.closed',
        ]);
        $this->actingAs($creator)->post('/inquiries/'.$inquiry->id.'/reply',[
            'body'=>'This message cannot be sent to closed inquiries.',
        ])->assertStatus(409);
        $this->assertDatabaseCount('service_inquiry_messages',2);
    }

    public function test_unpublished_and_product_listings_do_not_accept_private_service_inquiries(): void
    {
        $client=$this->user('customer');
        $creator=$this->user('freelancer');
        $draft=$this->listing($creator,'freelance','pending');
        $product=$this->listing($creator,'product','published');
        $values=[
            'subject'=>'Private consultation request',
            'body'=>str_repeat('A project inquiry with real detailed requirements. ',2),
        ];
        $this->actingAs($client)->post('/inquiries/for/'.$draft->id,$values)->assertStatus(409);
        $this->post('/inquiries/for/'.$product->id,$values)->assertStatus(409);
        $this->assertDatabaseCount('service_inquiries',0);
    }

    public function test_creator_cannot_contact_self_and_staff_cannot_read_private_threads(): void
    {
        $creator=$this->user('freelancer');
        $client=$this->user('customer');
        $staff=$this->user('staff');
        $listing=$this->listing($creator);
        $inquiry=$this->send($client,$listing);

        $this->actingAs($staff)->get('/inquiries/'.$inquiry->id)->assertNotFound();
        $this->get('/account/inquiries')->assertForbidden();
        $this->actingAs($creator)->post('/inquiries/for/'.$listing->id,[
            'subject'=>'Attempted creator self-inquiry',
            'body'=>str_repeat('This is a self-referencing project brief. ',2),
        ])->assertForbidden();

        $this->actingAs($client)->get('/studio/inquiries')->assertForbidden();
    }

    public function test_admin_operational_report_is_metadata_only_ten_per_page(): void
    {
        $admin=$this->user('admin');
        $creator=$this->user('freelancer');
        $client=$this->user('customer');
        $listing=$this->listing($creator);

        for($i=0;$i<12;$i++){
            $inquiry=ServiceInquiry::query()->create([
                'marketplace_listing_id'=>$listing->id,
                'client_id'=>$client->id,
                'creator_id'=>$creator->id,
                'subject'=>'Custom Laravel inquiry '.$i,
                'status'=>'open',
            ]);
            ServiceInquiryMessage::query()->create([
                'service_inquiry_id'=>$inquiry->id,
                'sender_id'=>$client->id,
                'body'=>'Confidential client implementation details only participants should view.',
            ]);
        }
        $this->actingAs($admin)->get('/dashboard/service-inquiries')->assertOk()
            ->assertViewHas('inquiries',fn($page)=>$page->count()===10 && $page->total()===12)
            ->assertDontSee('Confidential client implementation details only participants');
        $this->get('/inquiries/1')->assertNotFound();
    }

    public function test_private_inquiry_inbox_supports_status_and_search_without_leaking_conversations(): void
    {
        $creator=$this->user('freelancer');
        $otherCreator=$this->user('freelancer');
        $client=$this->user('customer');
        $otherClient=$this->user('customer');
        $listing=$this->listing($creator);
        $otherListing=$this->listing($otherCreator);
        $own=$this->send($client,$listing);
        $own->update(['subject'=>'Private Laravel migration request']);
        ServiceInquiry::query()->create([
            'marketplace_listing_id'=>$listing->id,
            'client_id'=>$otherClient->id,
            'creator_id'=>$creator->id,
            'subject'=>'Closed customer design request',
            'status'=>'closed',
        ]);
        ServiceInquiry::query()->create([
            'marketplace_listing_id'=>$otherListing->id,
            'client_id'=>$otherClient->id,
            'creator_id'=>$otherCreator->id,
            'subject'=>'Other creator confidential inquiry',
            'status'=>'open',
        ]);

        $this->actingAs($creator)->get('/studio/inquiries?status=open&q=migration')
            ->assertOk()->assertSee('Private Laravel migration request')
            ->assertDontSee('Closed customer design request')
            ->assertDontSee('Other creator confidential inquiry')
            ->assertSee('Private conversation status')
            ->assertViewHas('inquiries',fn($page)=>$page->total()===1);
        $this->get('/studio/inquiries?status=closed')->assertOk()
            ->assertSee('Closed customer design request')
            ->assertDontSee('Private Laravel migration request')
            ->assertDontSee('Other creator confidential inquiry');
        $this->get('/studio/inquiries?status=unknown')->assertNotFound();
        $this->actingAs($client)->get('/account/inquiries?status=open')->assertOk()
            ->assertSee('Private Laravel migration request')
            ->assertDontSee('Closed customer design request');
        $this->get('/account/inquiries?q='.str_repeat('a',121))->assertNotFound();
        $this->actingAs($otherClient)->get('/account/inquiries')->assertOk()
            ->assertDontSee('Private Laravel migration request');
    }

    public function test_localized_inquiry_routes_keep_the_same_creator_and_client_permissions(): void
    {
        $client = $this->user('customer');
        $creator = $this->user('freelancer');
        $other = $this->user('customer');
        $listing = $this->listing($creator);
        $inquiry = $this->send($client, $listing);
        $this->assertSame('open', $inquiry->status);

        // The English route has a different *name*, not a different user role.
        $this->actingAs($creator)->get('/en/studio/inquiries?status=open')
            ->assertOk()
            ->assertSee('Client Inquiries')
            ->assertSee($inquiry->subject)
            ->assertSee('Private conversation status');
        $this->get('/en/studio/inquiries?status=closed')
            ->assertOk()->assertDontSee($inquiry->subject);
        $this->get('/en/account/inquiries')->assertForbidden();

        $this->actingAs($client)->get('/en/account/inquiries?status=open')
            ->assertOk()
            ->assertSee('My Service Requests')
            ->assertSee($inquiry->subject);
        $this->get('/en/studio/inquiries')->assertForbidden();

        $this->actingAs($other)->get('/en/account/inquiries')
            ->assertOk()->assertDontSee($inquiry->subject);
        $this->get('/en/inquiries/'.$inquiry->id)->assertNotFound();
    }
}
