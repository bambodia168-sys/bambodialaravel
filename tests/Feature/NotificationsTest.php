<?php

namespace Tests\Feature;

use App\Models\AccountNotification;
use App\Models\JobProposal;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\ServiceInquiry;
use App\Models\User;
use App\Notifications\CheckoutVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role): User
    {
        return User::query()->create([
            'name'=>'Notification '.$role,
            'email'=>uniqid($role,true).'@example.test',
            'password'=>'LongAndSecure123!',
            'role'=>$role,
        ]);
    }

    public function test_notifications_are_private_to_the_recipient(): void
    {
        $customer=$this->member('customer');
        $other=$this->member('customer');
        AccountNotification::deliver($customer->id,'inquiry.received','Private title','A private event.', '/account/downloads');
        $note=AccountNotification::firstOrFail();

        $this->actingAs($other)->get('/account/notifications')->assertOk()->assertDontSee('Private title');
        $this->patch('/account/notifications/'.$note->id)->assertNotFound();
        $this->assertNull($note->fresh()->read_at);

        $this->actingAs($customer)->get('/account/notifications')->assertOk()->assertSee('Private title');
        $this->patch('/account/notifications/'.$note->id)->assertRedirect('/account/downloads');
        $this->assertNotNull($note->fresh()->read_at);
        $this->get('/account/notifications?filter=unread')->assertDontSee('Private title');
    }

    public function test_mark_all_read_changes_only_the_current_users_records(): void
    {
        $a=$this->member('customer');
        $b=$this->member('customer');
        foreach ([$a->id,$a->id,$b->id] as $id) {
            AccountNotification::deliver($id,'job.reviewed','Job reviewed','A job status was reviewed.','/my-jobs');
        }
        $this->actingAs($a)->patch('/account/notifications/read-all')->assertRedirect();
        $this->assertSame(0,AccountNotification::query()->where('user_id',$a->id)->whereNull('read_at')->count());
        $this->assertSame(1,AccountNotification::query()->where('user_id',$b->id)->whereNull('read_at')->count());
    }

    public function test_workspace_bell_lists_only_recipient_unread_items_and_public_headers_have_no_bell(): void
    {
        $member = $this->member('freelancer');
        $other = $this->member('customer');
        AccountNotification::deliver($member->id, 'listing.reviewed', 'Unread listing update', 'Selected for review.', '/studio/listings');
        $unread = AccountNotification::query()->latest('id')->firstOrFail();
        AccountNotification::deliver($member->id, 'listing.reviewed', 'Previously read update', 'Read already.', '/studio/listings');
        AccountNotification::query()->latest('id')->firstOrFail()->update(['read_at' => now()]);
        AccountNotification::deliver($other->id, 'listing.reviewed', 'Somebody else’s update', 'Private item.', '/account');

        $this->get('/account/notifications/tray')->assertRedirect();
        $this->actingAs($member)->get('/studio/listings')->assertOk()
            ->assertSeeInOrder(['workspace-home-link', 'data-workspace-notifications', 'data-theme-toggle'], false)
            ->assertSee('Notifications, 1 unread')->assertSee('All notifications')
            ->assertDontSee('Previously read update')->assertDontSee('Somebody else’s update');
        $this->get('/account/notifications/tray')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Unread listing update')->assertDontSee('Previously read update');
        $this->get('/')->assertOk()->assertDontSee('data-workspace-notifications', false)
            ->assertDontSee('Unread listing update');
        $this->get('/builder?type=freelance')->assertOk()->assertDontSee('data-workspace-notifications', false);

        $this->patch('/account/notifications/'.$unread->id)->assertRedirect('/studio/listings');
        $this->get('/account/notifications/tray')->assertSee('You’re all caught up.')
            ->assertDontSee('Unread listing update');
        $this->get('/account/notifications')->assertSee('Unread listing update')
            ->assertSee('Previously read update')->assertDontSee('Somebody else’s update');
    }

    public function test_listing_submission_notifies_submitter_and_reviewers_with_current_permissions(): void
    {
        $member = $this->member('freelancer');
        $reviewer = $this->member('admin');
        $excluded = $this->member('admin');
        $staff = $this->member('staff');
        DB::table('user_permission_overrides')->insert([
            'user_id' => $excluded->id, 'permission' => 'marketplace_review', 'allowed' => false,
        ]);

        $this->actingAs($member)->post('/studio/listings', [
            'type' => 'freelance',
            'title' => 'Develop a Laravel API',
            'description' => 'I will build and document a reliable API for your Laravel application.',
        ])->assertRedirect();
        $listing = MarketplaceListing::query()->firstOrFail();
        $this->assertDatabaseHas('account_notifications', [
            'user_id' => $member->id, 'event' => 'marketplace.submission.received',
            'target_path' => '/builder?='.$listing->public_id,
        ]);
        $reviewAlert = AccountNotification::query()->where('event', 'marketplace.review.requested')->sole();
        $this->assertEquals($reviewer->id, $reviewAlert->user_id);
        $this->assertDatabaseHas('email_deliveries', ['user_id'=>$member->id, 'event'=>'marketplace.submission.received', 'status'=>'queued']);
        $this->assertDatabaseHas('email_deliveries', ['user_id'=>$reviewer->id, 'event'=>'marketplace.review.requested', 'status'=>'queued']);
        $this->assertDatabaseMissing('email_deliveries', ['user_id'=>$excluded->id, 'event'=>'marketplace.review.requested']);
        $this->assertDatabaseMissing('email_deliveries', ['user_id'=>$staff->id, 'event'=>'marketplace.review.requested']);
        $this->actingAs($reviewer)->get('/dashboard')->assertOk()->assertSee('Notifications, 1 unread');
        $this->get('/account/notifications/tray')->assertSee('Listing ready for review');
        $this->actingAs($staff)->get('/account/notifications/tray')->assertOk()->assertDontSee('Listing ready for review');
        $this->actingAs($reviewer)->patch('/account/notifications/'.$reviewAlert->id)
            ->assertRedirect($reviewAlert->target_path);
        $this->get($reviewAlert->target_path)->assertOk()->assertSee('Develop a Laravel API');
        $this->patch('/dashboard/review-queue/'.$listing->id, ['decision' => 'rejected'])->assertRedirect();
        $this->assertDatabaseHas('account_notifications', [
            'user_id' => $member->id, 'event' => 'listing.reviewed',
            'target_path' => '/builder?='.$listing->public_id,
        ]);

        DB::table('user_permission_overrides')->insert([
            'user_id' => $reviewer->id, 'permission' => 'marketplace_review', 'allowed' => false,
        ]);
        $this->get('/account/notifications')->assertDontSee('Listing ready for review');
        $this->patch('/account/notifications/'.$reviewAlert->id)->assertNotFound();
    }

    public function test_sole_super_admin_submission_creates_both_distinct_notifications_and_emails(): void
    {
        $owner = $this->member('super_admin');
        $this->actingAs($owner)->post('/studio/listings', [
            'type' => 'freelance',
            'title' => 'Sole administrator listing',
            'description' => 'A genuine marketplace submission for review by the only authorized administrator.',
        ])->assertRedirect();
        $listing = MarketplaceListing::query()->sole();
        $this->assertSame('pending', $listing->status);
        $confirmation = AccountNotification::query()->where('user_id', $owner->id)
            ->where('event', 'marketplace.submission.received')->sole();
        $review = AccountNotification::query()->where('user_id', $owner->id)
            ->where('event', 'marketplace.review.requested')->sole();
        $this->assertSame('/builder?='.$listing->public_id, $confirmation->target_path);
        $this->assertNotSame($confirmation->target_path, $review->target_path);
        foreach (['marketplace.submission.received', 'marketplace.review.requested'] as $event) {
            $this->assertSame(1, \App\Models\EmailDelivery::query()->where('user_id', $owner->id)
                ->where('event', $event)->where('status', 'queued')->count());
        }
        $this->get('/account/notifications/tray')->assertOk()
            ->assertSee('Listing submitted for review')->assertSee('Listing ready for review');
        $this->patch('/account/notifications/'.$review->id)->assertRedirect($review->target_path);
        $this->get($review->target_path)->assertOk()->assertSee($listing->title);
        $this->assertSame('pending', $listing->fresh()->status);
    }

    public function test_self_submission_does_not_bypass_review_permissions_or_notify_for_drafts(): void
    {
        $owner = $this->member('admin');
        DB::table('user_permission_overrides')->insert([
            'user_id' => $owner->id, 'permission' => 'marketplace_review', 'allowed' => false,
        ]);
        $record = MarketplaceListing::query()->create([
            'user_id' => $owner->id, 'type' => 'service', 'title' => 'Permission fixture',
            'description' => 'A fixture for checking that review access is never expanded.', 'status' => 'draft',
        ]);
        \App\Support\SubmissionNotifications::send($record);
        $this->assertDatabaseCount('account_notifications', 0);
        $record->update(['status' => 'pending']);
        \App\Support\SubmissionNotifications::send($record);
        $this->assertDatabaseHas('account_notifications', [
            'user_id' => $owner->id, 'event' => 'marketplace.submission.received',
        ]);
        $this->assertDatabaseMissing('account_notifications', [
            'user_id' => $owner->id, 'event' => 'marketplace.review.requested',
        ]);
        $this->assertDatabaseMissing('email_deliveries', [
            'user_id' => $owner->id, 'event' => 'marketplace.review.requested',
        ]);
    }

    public function test_checkout_verification_email_status_generates_private_notifications(): void
    {
        config(['commerce.enabled' => true]);
        Notification::fake();
        $member = $this->member('customer');

        $this->actingAs($member)->post(route('checkout.verification.send'))->assertRedirect(route('verification.notice'));
        Notification::assertSentTo($member, CheckoutVerification::class);
        $this->assertDatabaseHas('account_notifications', ['user_id' => $member->id, 'event' => 'account.email.verification_sent']);

        $url = (new CheckoutVerification())->url($member);
        $this->get($url)->assertRedirect(route('verification.verified'));
        $this->get($url)->assertRedirect(route('verification.verified'));
        $this->assertSame(1, AccountNotification::query()->where('user_id', $member->id)
            ->where('event', 'account.email.verified')->count());
    }

    public function test_unsafe_notification_target_is_rejected(): void
    {
        $user=$this->member('customer');
        $this->expectException(\InvalidArgumentException::class);
        AccountNotification::deliver($user->id,'job.reviewed','Unsafe','An untrusted external target.','//malicious.example/path');
    }

    public function test_job_proposal_creates_real_notification_for_owner(): void
    {
        $client=$this->member('customer');
        $freelancer=$this->member('freelancer');
        $admin=$this->member('admin');
        $job=MarketplaceJob::query()->create([
            'client_id'=>$client->id,'title'=>'A Laravel Application Project',
            'description'=>str_repeat('Professional details and scope for the project. ',2),
            'category'=>'development','status'=>'pending',
        ]);
        $this->actingAs($admin)->patch('/dashboard/job-manager/'.$job->id,[
            'decision'=>'published',
        ])->assertRedirect();
        $this->assertDatabaseHas('account_notifications',[
            'user_id'=>$client->id,'event'=>'job.reviewed',
        ]);

        \App\Models\FreelancerResume::query()->create([
            'user_id'=>$freelancer->id,'headline'=>'Professional Laravel Engineer',
            'summary'=>str_repeat('I build original secure web applications. ',2),
            'experience'=>str_repeat('I have delivered working full-stack software. ',2),
            'skills'=>'Laravel, PHP, MySQL, JavaScript',
            'is_visible'=>true,
        ]);
        $this->actingAs($freelancer)->post('/jobs/'.$job->id.'/apply',[
            'cover_message'=>str_repeat('I can complete the requested system with proper testing. ',2),
        ])->assertRedirect();
        $this->assertDatabaseHas('account_notifications',[
            'user_id'=>$client->id,'event'=>'job.proposal.received',
        ]);

        $proposal=JobProposal::query()->firstOrFail();
        $this->actingAs($client)->patch('/my-jobs/proposals/'.$proposal->id,[
            'decision'=>'shortlisted',
        ])->assertRedirect();
        $this->assertDatabaseHas('account_notifications',[
            'user_id'=>$freelancer->id,'event'=>'job.proposal.decision',
        ]);
    }

    public function test_service_inquiry_reply_notifies_only_other_participant(): void
    {
        $client=$this->member('customer');
        $creator=$this->member('freelancer');
        $listing=MarketplaceListing::query()->create([
            'user_id'=>$creator->id,'type'=>'service','title'=>'Custom Laravel Applications',
            'description'=>str_repeat('Professional custom service implementation. ',2),
            'status'=>'published',
        ]);
        $this->actingAs($client)->post('/inquiries/for/'.$listing->id,[
            'subject'=>'A detailed private project inquiry',
            'body'=>str_repeat('I would like to hire you for a professional service. ',2),
        ])->assertRedirect();
        $inquiry=ServiceInquiry::firstOrFail();
        $this->assertDatabaseHas('account_notifications',[
            'user_id'=>$creator->id,'event'=>'inquiry.received',
        ]);

        $this->actingAs($creator)->post('/inquiries/'.$inquiry->id.'/reply',[
            'body'=>'Thank you. I can discuss this scope privately.',
        ])->assertRedirect();
        $this->assertDatabaseHas('account_notifications',[
            'user_id'=>$client->id,'event'=>'inquiry.replied',
        ]);

        $this->actingAs($client)->get('/account/notifications')->assertSee('New inquiry message');
        $this->get('/dashboard/notification-analytics')->assertForbidden();
        $this->actingAs($this->member('admin'))->get('/dashboard/notification-analytics')
            ->assertOk()->assertSee('Notification Analytics');
    }

    public function test_account_verification_is_independent_of_commerce_and_legacy_bell_links(): void
    {
        config(['commerce.enabled'=>false]);
        Notification::fake();
        $member = $this->member('freelancer');
        $other = $this->member('customer');
        AccountNotification::deliver($member->id,'account.email.verification_sent','Verification email sent','Old checkout copy.','/checkout/verify-email');
        $note = AccountNotification::query()->where('user_id',$member->id)->firstOrFail();
        $this->actingAs($other)->patch('/account/notifications/'.$note->id)->assertNotFound();
        $this->actingAs($member)->patch('/account/notifications/'.$note->id)->assertRedirect(route('verification.notice'));
        $this->get('/verify-email')->assertOk()->assertSee('Verify your email')->assertDontSee('Back to cart')->assertDontSee('checkout-progress',false);
        $this->get('/email-verified')->assertRedirect(route('verification.notice'));
        $this->post('/verify-email')->assertRedirect(route('verification.notice'));
        Notification::assertSentTo($member, CheckoutVerification::class);
        $url = (new CheckoutVerification)->url($member);
        $this->actingAs($other)->get($url)->assertForbidden();
        $this->assertNull($member->fresh()->email_verified_at);
        $this->actingAs($member)->get($url.'&tampered=1')->assertForbidden();
        $this->get($url)->assertRedirect(route('verification.verified'));
        $this->get('/email-verified')->assertOk()->assertSee('Your email verified')->assertDontSee('Continue checkout');
        $this->get('/verify-email')->assertRedirect(route('verification.verified'));
        $this->get('/checkout/verify-email')->assertRedirect(route('verification.notice'));
        $this->patch('/account/notifications/'.$note->id)->assertRedirect(route('verification.verified'));
        $this->assertNotNull($member->fresh()->email_verified_at);
    }

    public function test_existing_signed_checkout_verification_link_remains_valid(): void
    {
        config(['commerce.enabled'=>false]);
        $member = $this->member('admin');
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('checkout.verify',now()->addHour(),[
            'id'=>$member->id,'hash'=>sha1($member->getEmailForVerification()),
        ]);
        $this->actingAs($member)->get($url)->assertRedirect(route('verification.verified'));
        $this->assertNotNull($member->fresh()->email_verified_at);
    }

}
