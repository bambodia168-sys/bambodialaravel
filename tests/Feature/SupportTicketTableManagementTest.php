<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportTicketTableManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'customer'): User
    {
        return User::query()->create([
            'name' => 'Ticket '.ucfirst($role).' '.uniqid(),
            'email' => uniqid('tickets', true).'@example.test',
            'password' => 'TestPassword123!',
            'role' => $role,
        ]);
    }

    private function ticket(User $owner, string $subject = 'Help with purchased license'): SupportTicket
    {
        $ticket = SupportTicket::query()->create([
            'user_id' => $owner->id,
            'subject' => $subject,
            'status' => 'open',
        ]);
        $ticket->messages()->create([
            'user_id' => $owner->id,
            'body' => '<p>My license is unavailable after purchase.</p>',
        ]);

        return $ticket;
    }

    public function test_customer_premium_table_has_filters_per_page_and_pagination_go_to(): void
    {
        $customer = $this->user();
        for ($i = 0; $i < 34; $i++) {
            $this->ticket($customer, 'Ticket number '.($i + 1));
        }
        $this->ticket($this->user(), 'Other customer private ticket');

        $this->actingAs($customer)->get('/account/support-tickets')
            ->assertOk()
            ->assertSee('Search tickets')
            ->assertSee('Assigned To')
            ->assertSee('Go To')
            ->assertSee('Previous')
            ->assertSee('Next')
            ->assertSee('10 items')
            ->assertSee('20 items')
            ->assertSee('30 items')
            ->assertSee('row-action-trigger')
            ->assertDontSee('Other customer private ticket')
            ->assertViewHas('tickets', fn ($tickets) => $tickets->perPage() === 10 && $tickets->total() === 34);

        $this->get('/account/support-tickets?per_page=20&page=2')
            ->assertOk()
            ->assertViewHas('tickets', fn ($tickets) => $tickets->perPage() === 20
                && $tickets->currentPage() === 2 && $tickets->count() === 14);

        $this->get('/account/support-tickets?per_page=30&page=2')
            ->assertOk()
            ->assertViewHas('tickets', fn ($tickets) => $tickets->perPage() === 30
                && $tickets->currentPage() === 2 && $tickets->count() === 4);

        $this->get('/account/support-tickets?q=number%2017')
            ->assertOk()
            ->assertViewHas('tickets', fn ($tickets) => $tickets->total() === 1);
        $this->get('/account/support-tickets?per_page=999')
            ->assertSessionHasErrors('per_page');
    }

    public function test_all_role_workspaces_use_shared_premium_table(): void
    {
        foreach ([
            'customer' => '/account/support-tickets',
            'subscriber' => '/account/support-tickets',
            'freelancer' => '/studio/support-tickets',
            'staff' => '/dashboard/support-tickets',
            'admin' => '/dashboard/support-tickets',
            'super_admin' => '/dashboard/support-tickets',
        ] as $role => $path) {
            $user = $this->user($role);
            $ticket = $this->ticket($user);
            $this->actingAs($user)->get($path)
                ->assertOk()
                ->assertSee('Go To')
                ->assertSee('10 items')
                ->assertSee('Create support ticket')
                ->assertSee((string) $ticket->public_id);
        }
    }

    public function test_customer_can_edit_and_soft_delete_only_unhandled_own_ticket(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $ticket = $this->ticket($owner);

        $this->actingAs($stranger)->get('/account/support-tickets/'.$ticket->public_id.'/edit')
            ->assertNotFound();
        $this->patch('/account/support-tickets/'.$ticket->public_id, [
            'subject' => 'Attempt to modify someone else ticket',
            'message' => '<p>Unauthorized edit.</p>',
        ])->assertNotFound();
        $this->delete('/account/support-tickets/'.$ticket->public_id)->assertNotFound();

        $this->actingAs($owner)->get('/account/support-tickets/'.$ticket->public_id.'/edit')
            ->assertOk()->assertSee('Edit support ticket');
        $this->patch('/account/support-tickets/'.$ticket->public_id, [
            'subject' => 'Edited purchased license issue',
            'message' => '<p>Updated request with <strong>important</strong> details.</p><script>alert(1)</script>',
        ])->assertRedirect();

        $this->assertSame('Edited purchased license issue', $ticket->fresh()->subject);
        $this->assertStringContainsString('<strong>important</strong>', $ticket->messages()->first()->body);
        $this->assertStringNotContainsString('<script', $ticket->messages()->first()->body);

        $this->delete('/account/support-tickets/'.$ticket->public_id)->assertRedirect();
        $this->assertSoftDeleted('support_tickets', ['id' => $ticket->id]);
        $this->assertSame(1, $ticket->messages()->count());
        $this->get('/account/support-tickets/'.$ticket->public_id)->assertNotFound();
    }

    public function test_owner_cannot_edit_or_delete_after_support_reply_or_when_resolved(): void
    {
        $owner = $this->user();
        $staff = $this->user('staff');
        $ticket = $this->ticket($owner);
        $ticket->messages()->create(['user_id' => $staff->id, 'body' => '<p>Review in progress.</p>']);

        $this->actingAs($owner)->get('/account/support-tickets/'.$ticket->public_id.'/edit')
            ->assertForbidden();
        $this->patch('/account/support-tickets/'.$ticket->public_id, [
            'subject' => 'Unauthorized late revision',
            'message' => '<p>Attempt to overwrite the request.</p>',
        ])->assertForbidden();
        $this->delete('/account/support-tickets/'.$ticket->public_id)->assertForbidden();
        $this->assertNull($ticket->fresh()->deleted_at);

        $resolved = $this->ticket($owner, 'Already resolved case');
        $resolved->update(['status' => 'resolved']);
        $this->delete('/account/support-tickets/'.$resolved->public_id)->assertForbidden();

        $closed = $this->ticket($owner, 'Closed case cannot receive replies');
        $closed->update(['status' => 'closed']);
        $this->post('/account/support-tickets/'.$closed->public_id.'/reply', [
            'message' => '<p>Attempt a closed reply.</p>',
        ])->assertForbidden();
    }

    public function test_support_staff_can_assign_and_transition_status_but_not_archive_or_edit(): void
    {
        $owner = $this->user();
        $staff = $this->user('staff');
        $assignee = $this->user('admin');
        $ticket = $this->ticket($owner);

        $this->actingAs($staff)->get('/dashboard/support-center')
            ->assertOk()->assertSee('Assigned To')->assertSee('Go To')
            ->assertSee('Assign to')->assertSee((string) $ticket->public_id);

        $this->patch('/dashboard/support-center/'.$ticket->public_id.'/assign', [
            'assigned_to' => $assignee->id,
        ])->assertRedirect();
        $this->assertSame($assignee->id, (int) $ticket->fresh()->assigned_to);

        $this->patch('/dashboard/support-center/'.$ticket->public_id.'/status', [
            'status' => 'pending',
        ])->assertRedirect();
        $this->assertSame('pending', $ticket->fresh()->status);

        $this->get('/dashboard/support-center?assigned_to='.$assignee->id.'&status=pending')
            ->assertOk()
            ->assertViewHas('tickets', fn ($tickets) => $tickets->total() === 1);

        $this->patch('/dashboard/support-center/'.$ticket->public_id.'/status', [
            'status' => 'in_progress',
        ])->assertRedirect();
        $this->patch('/dashboard/support-center/'.$ticket->public_id.'/status', [
            'status' => 'closed',
        ])->assertRedirect();
        $this->assertSame('closed', $ticket->fresh()->status);

        $this->patch('/dashboard/support-center/'.$ticket->public_id.'/status', [
            'status' => 'unsupported',
        ])->assertSessionHasErrors('status');

        $this->patch('/dashboard/support-center/'.$ticket->public_id.'/assign', [
            'assigned_to' => $owner->id,
        ])->assertStatus(422);

        $this->get('/dashboard/support-center/'.$ticket->public_id.'/edit')->assertForbidden();
        $this->patch('/dashboard/support-center/'.$ticket->public_id, [
            'subject' => 'Staff cannot edit private evidence',
        ])->assertForbidden();
        $this->delete('/dashboard/support-center/'.$ticket->public_id)->assertForbidden();
        $this->assertNull($ticket->fresh()->deleted_at);
    }

    public function test_admin_can_edit_subject_and_soft_delete_without_rewriting_conversation(): void
    {
        $owner = $this->user();
        $admin = $this->user('admin');
        $ticket = $this->ticket($owner);
        $original = $ticket->messages()->first()->body;

        $this->actingAs($admin)->get('/dashboard/support-center/'.$ticket->public_id.'/edit')
            ->assertOk()->assertSee('Edit support ticket');
        $this->patch('/dashboard/support-center/'.$ticket->public_id, [
            'subject' => 'Administrative ticket subject clarification',
            'message' => '<script>Should not be stored</script>',
        ])->assertRedirect();

        $this->assertSame('Administrative ticket subject clarification', $ticket->fresh()->subject);
        $this->assertSame($original, $ticket->messages()->first()->body);

        $this->delete('/dashboard/support-center/'.$ticket->public_id)->assertRedirect();
        $this->assertSoftDeleted('support_tickets', ['id' => $ticket->id]);
    }

    public function test_custom_assignee_filters_and_permissions_do_not_disclose_private_tickets(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $staff = $this->user('staff');
        $ticket = $this->ticket($owner);
        $this->actingAs($stranger)->get('/account/support-tickets?assigned_to=unassigned')
            ->assertSessionHasErrors('assigned_to');
        $this->get('/account/support-tickets/'.$ticket->public_id)->assertNotFound();

        $this->actingAs($owner)->patch('/dashboard/support-center/'.$ticket->public_id.'/status', [
            'status' => 'resolved',
        ])->assertForbidden();

        $this->actingAs($staff)->get('/dashboard/support-center?assigned_to=unassigned')
            ->assertOk()->assertViewHas('tickets', fn ($tickets) => $tickets->total() === 1);
    }
}
