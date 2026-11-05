<?php

namespace Tests\Feature;

use App\Models\AccountNotification;
use App\Models\JobProposal;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\ServiceInquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
