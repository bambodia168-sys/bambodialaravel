<?php

namespace Tests\Feature;

use App\Models\ContentEntry;
use App\Models\Policy;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentAndSupportTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'customer'): User
    {
        return User::query()->create([
            'name' => 'Test Member',
            'email' => uniqid('member', true).'@example.test',
            'password' => 'ValidPassword123!',
            'role' => $role,
        ]);
    }

    public function test_policy_requires_review_before_publication(): void
    {
        $admin = $this->account('admin');
        $this->actingAs($admin)->post('/dashboard/policy-center', [
            'title' => 'Marketplace Usage Policy',
            'body' => str_repeat('This policy describes marketplace obligations. ', 2),
            'category' => 'general',
        ])->assertRedirect();

        $policy = Policy::firstOrFail();
        $this->get('/policy-center')->assertDontSee('Marketplace Usage Policy');

        $this->actingAs($admin)->patch('/dashboard/policy-center/'.$policy->id, [
            'title' => $policy->title,
            'body' => $policy->body,
            'category' => 'general',
            'status' => 'published',
        ])->assertSessionHasErrors('status');

        $this->assertSame('draft', $policy->fresh()->status);

        $this->patch('/dashboard/policy-center/'.$policy->id, [
            'title' => $policy->title,
            'body' => $policy->body,
            'category' => 'general',
            'status' => 'published',
            'confirm_legal_review' => '1',
        ])->assertRedirect();

        $this->get('/policy-center')->assertSee('Marketplace Usage Policy');
        $this->get($policy->fresh()->publicUrl())->assertOk();
    }

    public function test_published_page_uses_nine_digit_id_and_underscore_slug(): void
    {
        $admin = $this->account('admin');
        $this->actingAs($admin)->post('/dashboard/page-manager', [
            'title' => 'About Bambodia',
            'body' => 'Independent creators and licensed work on a shared marketplace.',
            'status' => 'published',
        ])->assertRedirect();

        $entry = ContentEntry::firstOrFail();
        $this->assertMatchesRegularExpression('/^\d{9}_about-bambodia$/', $entry->public_id.'_'.$entry->slug);
        $this->get($entry->publicUrl())->assertOk()->assertSee('About Bambodia');
    }

    public function test_support_ticket_is_private_and_staff_can_reply(): void
    {
        $owner = $this->account();
        $stranger = $this->account();
        $staff = $this->account('staff');

        $this->actingAs($owner)->post('/support-center', [
            'subject' => 'Need help with my marketplace account',
            'message' => 'I cannot find a setting in my account profile.',
        ])->assertRedirect();

        $ticket = SupportTicket::firstOrFail();
        $this->assertDatabaseHas('audit_events', [
            'actor_id'=>$owner->id, 'event'=>'support.ticket.created',
        ]);
        $this->actingAs($stranger)->get('/support-center/'.$ticket->public_id)->assertNotFound();

        $this->actingAs($staff)->post('/dashboard/support-center/'.$ticket->public_id.'/reply', [
            'message' => 'Please use the profile settings form on your account page.',
            'status' => 'in_progress',
        ])->assertRedirect();

        $this->assertSame('in_progress', $ticket->fresh()->status);
        $this->assertSame(2, $ticket->messages()->count());
        $this->assertDatabaseHas('audit_events', [
            'actor_id'=>$staff->id, 'event'=>'support.staff.replied',
        ]);
        $this->assertDatabaseHas('account_notifications', [
            'user_id'=>$owner->id, 'event'=>'support.replied',
            'target_path'=>'/support-center/'.$ticket->public_id,
        ]);
        $this->actingAs($stranger)->get('/account/notifications')
            ->assertOk()->assertDontSee('Support team replied');
        $this->actingAs($owner)->get('/account/notifications')
            ->assertOk()->assertSee('Support team replied');
        $this->get('/support-center/'.$ticket->public_id)
            ->assertOk()->assertSee('Please use the profile settings form');
    }

    public function test_regular_customer_cannot_manage_published_content(): void
    {
        $this->actingAs($this->account())->get('/dashboard/post-manager')->assertForbidden();
    }

    public function test_support_status_changes_notify_once_and_audit_without_repeating_unchanged_actions(): void
    {
        $owner = $this->account();
        $staff = $this->account('staff');
        $customer = $this->account();
        $this->actingAs($owner)->post('/support-center', [
            'subject'=>'Support status acceptance verification',
            'message'=>'I need a verified support status change for my account.',
        ])->assertRedirect();
        $ticket = SupportTicket::firstOrFail();

        $this->actingAs($customer)->patch(
            '/dashboard/support-center/'.$ticket->public_id.'/status',
            ['status'=>'in_progress']
        )->assertForbidden();

        $this->actingAs($staff)->patch(
            '/dashboard/support-center/'.$ticket->public_id.'/status',
            ['status'=>'in_progress']
        )->assertRedirect();
        $this->assertSame('in_progress',$ticket->fresh()->status);
        $this->assertDatabaseHas('account_notifications',[
            'user_id'=>$owner->id, 'event'=>'support.status_changed',
        ]);
        $this->assertDatabaseHas('audit_events',[
            'actor_id'=>$staff->id, 'event'=>'support.status.changed',
        ]);

        $this->patch('/dashboard/support-center/'.$ticket->public_id.'/status', [
            'status'=>'in_progress',
        ])->assertRedirect();
        $this->assertSame(1, \App\Models\AccountNotification::query()
            ->where('user_id',$owner->id)->where('event','support.status_changed')->count());
        $this->assertSame(1, \App\Models\AuditEvent::query()
            ->where('actor_id',$staff->id)->where('event','support.status.changed')->count());

        $this->actingAs($owner)->post('/support-center/'.$ticket->public_id.'/reply', [
            'message'=>'Thank you. The ticket can be reopened for a follow-up.',
        ])->assertRedirect();
        $this->assertSame('open',$ticket->fresh()->status);
        $this->assertDatabaseHas('audit_events',[
            'actor_id'=>$owner->id, 'event'=>'support.customer.replied',
        ]);
        $this->assertSame(1, \App\Models\AccountNotification::query()
            ->where('user_id',$owner->id)->where('event','support.status_changed')->count());
    }
}
