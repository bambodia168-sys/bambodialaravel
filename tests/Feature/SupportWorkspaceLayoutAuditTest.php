<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupportWorkspaceLayoutAuditTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role): User
    {
        return User::query()->create([
            'name' => 'Layout '.ucfirst($role),
            'email' => uniqid('layout-', true).'@example.test',
            'password' => 'ThisIsATestPassword123!',
            'role' => $role,
        ]);
    }

    private function ticket(User $user): SupportTicket
    {
        $record = SupportTicket::query()->create([
            'user_id' => $user->id,
            'subject' => 'Support workspace role layout check',
            'status' => 'open',
        ]);
        $record->messages()->create([
            'user_id' => $user->id,
            'body' => '<p>Verify my support ticket workspace.</p>',
        ]);

        return $record;
    }

    public function test_all_primary_account_roles_use_the_correct_workspace_shell_on_ticket_pages(): void
    {
        foreach ([
            'customer' => ['/account/support-tickets', 'account', 'My Account'],
            'subscriber' => ['/account/support-tickets', 'account', 'My Account'],
            'freelancer' => ['/studio/support-tickets', 'creator', 'Creator Studio'],
            'staff' => ['/dashboard/support-tickets', 'admin', 'Administration'],
            'admin' => ['/dashboard/support-tickets', 'admin', 'Administration'],
            'super_admin' => ['/dashboard/support-tickets', 'admin', 'Administration'],
        ] as $role => [$path, $workspace, $name]) {
            $user = $this->account($role);
            $ticket = $this->ticket($user);

            foreach ([$path, $path.'/create', $path.'/'.$ticket->public_id, $path.'/'.$ticket->public_id.'/edit'] as $url) {
                $this->actingAs($user)->get($url)
                    ->assertOk()
                    ->assertSee('data-workspace="'.$workspace.'"', false)
                    ->assertSee('aria-label="'.$name.' menu"', false)
                    ->assertSee('Support &amp; Help', false)
                    ->assertSee('My Support Tickets')
                    ->assertDontSee('My Account Support');
            }
        }
    }

    public function test_super_admin_creator_studio_ticket_links_and_pages_do_not_jump_to_admin_shell(): void
    {
        $root = $this->account('super_admin');
        $ticket = $this->ticket($root);
        $base = '/studio/support-tickets';

        $this->actingAs($root)->get($base)
            ->assertOk()
            ->assertSee('data-workspace="creator"', false)
            ->assertSee('aria-label="Creator Studio menu"', false)
            ->assertSee(route('studio.support-tickets.create'))
            ->assertSee(route('studio.support-tickets.show', $ticket->public_id))
            ->assertSee(route('studio.support-tickets.edit', $ticket->public_id))
            ->assertDontSee('aria-label="Administration menu"', false);

        $this->get($base.'/create')->assertOk()
            ->assertSee('data-workspace="creator"', false)
            ->assertSee(route('studio.support-tickets.store'));

        $this->get($base.'/'.$ticket->public_id)->assertOk()
            ->assertSee('data-workspace="creator"', false)
            ->assertSee(route('studio.support-tickets.reply', $ticket->public_id));

        $this->get($base.'/'.$ticket->public_id.'/edit')->assertOk()
            ->assertSee('data-workspace="creator"', false)
            ->assertSee(route('studio.support-tickets.update', $ticket->public_id));

        $this->post($base, [
            'subject' => 'Creator Studio support inquiry',
            'message' => '<p>Keep my support response in Creator Studio.</p>',
        ])->assertRedirect();

        $newTicket = SupportTicket::query()->where('user_id', $root->id)
            ->where('subject', 'Creator Studio support inquiry')->firstOrFail();

        $this->assertSame(
            route('studio.support-tickets.show', $newTicket->public_id),
            route($root->supportTicketRoutePrefixForRequest().'.show', $newTicket->public_id)
        );
    }

    public function test_localized_creator_workspace_keeps_creator_shell_for_super_admin(): void
    {
        $root = $this->account('super_admin');
        $ticket = $this->ticket($root);

        $this->actingAs($root)->get('/en/studio/support-tickets')->assertOk()
            ->assertSee('data-workspace="creator"', false);
        $this->get('/en/studio/support-tickets/'.$ticket->public_id)->assertOk()
            ->assertSee('data-workspace="creator"', false);
    }

    public function test_staff_without_support_management_permission_still_has_personal_tickets_not_management_links(): void
    {
        $staff = $this->account('staff');
        DB::table('user_permission_overrides')->insert([
            'user_id' => $staff->id,
            'permission' => 'support_center',
            'allowed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($staff)->get('/dashboard')
            ->assertOk()
            ->assertSee('Support &amp; Help', false)
            ->assertSee('My Support Tickets')
            ->assertDontSee('title="Support Management"', false);

        $this->get('/dashboard/support-tickets')->assertOk()
            ->assertSee('data-workspace="admin"', false)
            ->assertDontSee('href="'.route('dashboard.support').'"', false);

        $this->get('/dashboard/support-center')->assertForbidden();
    }

    public function test_authorized_staff_have_both_personal_tickets_and_support_management(): void
    {
        $staff = $this->account('staff');

        $this->actingAs($staff)->get('/dashboard')
            ->assertOk()
            ->assertSee('title="My Support Tickets"', false)
            ->assertSee('title="Support Management"', false);

        $this->get('/dashboard/support-tickets')->assertOk()
            ->assertSee('data-workspace="admin"', false);
        $this->get('/dashboard/support-center')->assertOk()
            ->assertSee('Support Management');
    }
}
