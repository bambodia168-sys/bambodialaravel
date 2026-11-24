<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountSupportWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role): User
    {
        return User::query()->create([
            'name' => 'Workspace '.$role,
            'email' => uniqid('support', true).'@example.test',
            'password' => 'ValidPassword123!',
            'role' => $role,
        ]);
    }

    public function test_support_center_is_public_but_account_tickets_require_authentication(): void
    {
        $this->get('/support-center')->assertOk()->assertSee('How can we help?');
        $this->get('/account/support-tickets')->assertRedirect('/login');
        $this->get('/account/support-tickets/create')->assertRedirect('/login');
        $this->post('/account/support-tickets', [
            'subject' => 'Unauthenticated support request',
            'message' => 'Should never create a ticket.',
        ])->assertRedirect('/login');
        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_every_account_role_can_create_and_read_only_its_own_tickets(): void
    {
        foreach (['customer', 'subscriber', 'freelancer', 'staff', 'admin', 'super_admin'] as $role) {
            $user = $this->account($role);
            $this->actingAs($user)->get('/account/support-tickets')->assertOk()
                ->assertSee('My support tickets');
            $this->get('/account/support-tickets/create')->assertOk()
                ->assertSee('data-ticket-editor', false)
                ->assertSee('Create support ticket');

            $response = $this->post('/account/support-tickets', [
                'subject' => 'Support request from '.$role,
                'message' => '<p>Please help with my private account settings.</p>',
            ]);
            $ticket = SupportTicket::query()->where('user_id', $user->id)->firstOrFail();
            $response->assertRedirect(route('account.support.show', $ticket->public_id));
            $this->get('/account/support-tickets/'.$ticket->public_id)->assertOk()
                ->assertSee('Support request from '.$role);
        }
        $this->assertDatabaseCount('support_tickets', 6);
    }

    public function test_each_role_manages_personal_tickets_within_its_workspace(): void
    {
        $workspaces = [
            'customer' => '/account/support-tickets',
            'subscriber' => '/account/support-tickets',
            'freelancer' => '/studio/support-tickets',
            'staff' => '/dashboard/support-tickets',
            'admin' => '/dashboard/support-tickets',
            'super_admin' => '/dashboard/support-tickets',
        ];

        foreach ($workspaces as $role => $base) {
            $user = $this->account($role);

            $this->actingAs($user)->get($base)->assertOk()
                ->assertSee('My support tickets');
            $this->get($base.'/create')->assertOk()
                ->assertSee('data-ticket-editor', false);

            $this->post($base, [
                'subject' => 'Role workspace request from '.$role,
                'message' => '<p>Can you help with my private account issue?</p>',
            ])->assertRedirect();

            $ticket = SupportTicket::query()->where('user_id', $user->id)->firstOrFail();

            $this->get($base.'/'.$ticket->public_id)->assertOk()
                ->assertSee('Role workspace request from '.$role);
            $this->post($base.'/'.$ticket->public_id.'/reply', [
                'message' => '<p>This is my follow-up reply.</p>',
            ])->assertRedirect();

            $this->assertSame(2, $ticket->messages()->count());
            $this->assertSame($base.'/'.$ticket->public_id,
                parse_url(route($user->supportTicketRoutePrefix().'.show', $ticket->public_id), PHP_URL_PATH));
        }
    }

    public function test_personal_workspace_routes_reject_other_roles_and_other_owners(): void
    {
        $customer = $this->account('customer');
        $freelancer = $this->account('freelancer');
        $staff = $this->account('staff');

        $this->get('/dashboard/support-tickets')->assertRedirect('/login');
        $this->get('/studio/support-tickets')->assertRedirect('/login');

        $this->actingAs($customer)->get('/dashboard/support-tickets')->assertForbidden();
        $this->get('/studio/support-tickets')->assertForbidden();

        $this->actingAs($freelancer)->get('/dashboard/support-tickets')->assertForbidden();
        $this->actingAs($staff)->get('/studio/support-tickets')->assertForbidden();

        $this->actingAs($customer)->post('/account/support-tickets', [
            'subject' => 'Customer private ticket for account help',
            'message' => '<p>Private customer message for the support team.</p>',
        ])->assertRedirect();
        $ticket = SupportTicket::query()->where('user_id', $customer->id)->firstOrFail();

        $this->actingAs($staff)->get('/dashboard/support-tickets/'.$ticket->public_id)
            ->assertNotFound();
        $this->post('/dashboard/support-tickets/'.$ticket->public_id.'/reply', [
            'message' => '<p>Unauthorized personal workspace response.</p>',
        ])->assertNotFound();

        $this->actingAs($freelancer)->get('/studio/support-tickets/'.$ticket->public_id)
            ->assertNotFound();
        $this->assertSame(1, $ticket->messages()->count());
    }

    public function test_strangers_cannot_read_or_reply_to_private_support(): void
    {
        $owner = $this->account('customer');
        $other = $this->account('freelancer');
        $this->actingAs($owner)->post('/account/support-tickets', [
            'subject' => 'Private account ticket',
            'message' => 'Sensitive customer account assistance requested.',
        ])->assertRedirect();
        $ticket = SupportTicket::query()->firstOrFail();
        $this->actingAs($other)->get('/account/support-tickets/'.$ticket->public_id)->assertNotFound();
        $this->get('/support-center/'.$ticket->public_id)->assertNotFound();
        $this->post('/account/support-tickets/'.$ticket->public_id.'/reply', [
            'message' => 'I should not be able to reply',
        ])->assertNotFound();
        $this->assertSame(1, $ticket->messages()->count());
    }

    public function test_rich_ticket_messages_are_sanitized_and_staff_replies_still_work(): void
    {
        $owner = $this->account('customer');
        $staff = $this->account('staff');
        $this->actingAs($owner)->post('/account/support-tickets', [
            'subject' => 'Need help with PHP code and media',
            'message' => '<h2>Problem details</h2><p onclick="alert(1)">Please review this private example.</p>'
                .'<script>alert(1)</script>'
                .'<a href="javascript:alert(1)">Bad link</a>'
                .'<img src="javascript:alert(1)" onerror="alert(1)">'
                .'<pre><code class="language-php">echo 1;</code></pre>'
                .'<iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ" onload="alert(1)"></iframe>',
        ])->assertRedirect();

        $ticket = SupportTicket::query()->firstOrFail();
        $stored = $ticket->messages()->firstOrFail()->body;
        $this->assertStringContainsString('language-php', $stored);
        $this->assertStringContainsString('youtube-nocookie.com/embed/dQw4w9WgXcQ', $stored);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);
        $this->assertStringNotContainsString('onerror=', $stored);
        $this->assertStringNotContainsString('onclick=', $stored);
        $this->actingAs($owner)->get('/account/support-tickets/'.$ticket->public_id)
            ->assertOk()->assertSee('echo 1;')->assertDontSee('javascript:alert(1)');

        $this->actingAs($staff)->post('/dashboard/support-center/'.$ticket->public_id.'/reply', [
            'message' => '<p><strong>We can help.</strong> Please check the account.</p>',
            'status' => 'resolved',
        ])->assertRedirect();
        $this->assertSame('resolved', $ticket->fresh()->status);
        $this->assertSame(2, $ticket->messages()->count());
        $this->actingAs($owner)->post('/account/support-tickets/'.$ticket->public_id.'/reply', [
            'message' => '<p>Thank you, one more follow-up.</p>',
        ])->assertRedirect();
        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertSame(3, $ticket->messages()->count());
    }

    public function test_empty_or_unsafe_only_html_is_rejected(): void
    {
        $this->actingAs($this->account('customer'))->post('/account/support-tickets', [
            'subject' => 'HTML content validation issue',
            'message' => '<script>alert(1)</script>',
        ])->assertSessionHasErrors('message');
        $this->assertDatabaseCount('support_tickets', 0);
    }
}
