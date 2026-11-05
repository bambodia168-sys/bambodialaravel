<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_and_successful_logins_create_real_audit_entries(): void
    {
        $user = User::query()->create([
            'name' => 'Security Owner',
            'email' => 'security@example.test',
            'password' => 'LongPassword123!',
            'role' => 'super_admin',
        ]);

        $this->post('/login', [
            'email' => 'security@example.test',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');
        $this->assertDatabaseHas('audit_events', ['event' => 'auth.login.failed', 'actor_id' => null]);

        $this->post('/login', [
            'email' => 'security@example.test',
            'password' => 'LongPassword123!',
        ])->assertRedirect('/dashboard');
        $this->assertDatabaseHas('audit_events', ['event' => 'auth.login.success', 'actor_id' => $user->id]);

        $event = AuditEvent::query()->where('event', 'auth.login.failed')->firstOrFail();
        $this->assertSame(16, strlen($event->ip_fingerprint));
        $this->assertArrayNotHasKey('password', $event->getAttributes());
        $this->assertArrayNotHasKey('email', $event->getAttributes());

        $this->post('/logout')->assertRedirect();
        $this->assertDatabaseHas('audit_events', ['event' => 'auth.logout', 'actor_id' => $user->id]);
    }

    public function test_only_super_admin_sees_real_security_counts_and_filtered_pages(): void
    {
        $admin = User::query()->create([
            'name' => 'Security Admin',
            'email' => 'admin@example.test',
            'password' => 'LongPassword123!',
            'role' => 'super_admin',
        ]);
        for ($i = 0; $i < 14; $i++) {
            AuditEvent::record('auth.login.failed');
        }
        [$token] = ApiToken::issue($admin, 'Security dashboard token', ['account.read'], 30);

        $this->actingAs($admin)->get('/security-dashboard?event=auth.login.failed')
            ->assertOk()
            ->assertViewHas('events', fn ($paginator) => $paginator->count() === 10 && $paginator->total() === 14)
            ->assertViewHas('activeTokens', 1);
        $this->assertDatabaseHas('api_tokens', ['id' => $token->id]);

        $admin->update(['role' => 'staff']);
        $this->actingAs($admin)->get('/security-dashboard')->assertForbidden();
    }
}
