<?php

namespace Tests\Feature;

use App\Models\AccountNotification;
use App\Models\AuditEvent;
use App\Models\SecurityAlert;
use App\Models\SecurityAlertRule;
use App\Models\User;
use App\Support\Security\SecurityAlertMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class SecurityAlertMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role='super_admin', string $status='active'): User
    {
        return User::query()->create([
            'name'=>'Security monitor '.$role,
            'email'=>uniqid($role,true).'@example.test',
            'password'=>'StrongPasswordForMonitoring123!',
            'role'=>$role,
            'account_status'=>$status,
        ]);
    }

    private function payload(array $changes=[]): array
    {
        return array_replace([
            'name'=>'Repeated failed sign-ins',
            'event'=>'auth.login.failed',
            'threshold'=>3,
            'window_minutes'=>15,
            'cooldown_minutes'=>60,
            'is_enabled'=>1,
        ],$changes);
    }

    private function failures(int $count, int $minutesAgo=1): void
    {
        for($i=0;$i<$count;$i++) {
            AuditEvent::query()->create([
                'event'=>'auth.login.failed',
                'created_at'=>now()->subMinutes($minutesAgo),
            ]);
        }
    }

    public function test_super_admin_creates_and_updates_real_rule_in_original_security_center(): void
    {
        $owner=$this->member();
        $this->actingAs($owner)->get(route('security.index'))
            ->assertOk()
            ->assertSee('Audit alert rules')
            ->assertSee('Security alerts')
            ->assertSee('Run check')
            ->assertSee('Add security alert rule');

        $this->post(route('security.rules.store'),$this->payload())->assertRedirect();
        $rule=SecurityAlertRule::query()->firstOrFail();
        $this->assertTrue($rule->is_enabled);
        $this->assertSame(15,$rule->window_minutes);
        $this->assertDatabaseHas('audit_events',['event'=>'security.alert.rule.created']);

        $this->get(route('security.index'))->assertOk()
            ->assertSee('Repeated failed sign-ins')
            ->assertSee('Edit security alert rule');

        $this->patch(route('security.rules.update',$rule),$this->payload([
            'name'=>'Two-factor failures',
            'event'=>'auth.two_factor.challenge.failed',
            'threshold'=>5,
            'cooldown_minutes'=>240,
            'is_enabled'=>0,
        ]))->assertRedirect();
        $this->assertFalse($rule->fresh()->is_enabled);
        $this->assertSame('auth.two_factor.challenge.failed',$rule->fresh()->event);
        $this->assertDatabaseHas('audit_events',['event'=>'security.alert.rule.updated']);
    }

    public function test_rule_validation_rejects_unsupported_event_and_duplicate_rules(): void
    {
        $owner=$this->member();
        $this->actingAs($owner)->post(route('security.rules.store'),
            $this->payload(['event'=>'site.social.created']))->assertSessionHasErrors('event');

        $this->post(route('security.rules.store'),$this->payload([
            'threshold'=>0,
            'window_minutes'=>17,
            'cooldown_minutes'=>0,
        ]))->assertSessionHasErrors(['threshold','window_minutes','cooldown_minutes']);
        $this->assertDatabaseCount('security_alert_rules',0);

        $this->post(route('security.rules.store'),$this->payload())->assertRedirect();
        $this->post(route('security.rules.store'),$this->payload([
            'name'=>'Duplicate same monitored event',
        ]))->assertSessionHasErrors('event');
        $this->assertDatabaseCount('security_alert_rules',1);
    }

    public function test_real_audit_threshold_creates_single_alert_and_notifies_active_owners_only(): void
    {
        $owner=$this->member();
        $second=$this->member();
        $inactive=$this->member('super_admin','suspended');
        $staff=$this->member('staff');
        $rule=SecurityAlertRule::query()->create($this->payload());
        $this->failures(4);

        $scan=app(SecurityAlertMonitor::class);
        $this->assertSame(1,$scan->scan());
        $this->assertSame(0,$scan->scan());
        $alert=SecurityAlert::query()->firstOrFail();
        $this->assertSame($rule->id,$alert->security_alert_rule_id);
        $this->assertSame(4,$alert->observed_count);
        $this->assertNull($alert->acknowledged_at);
        $this->assertNotNull($rule->fresh()->last_checked_at);
        $this->assertNotNull($rule->fresh()->last_triggered_at);
        $this->assertDatabaseHas('audit_events',['event'=>'security.alert.triggered']);

        $notices=AccountNotification::query()->where('event','security.alert.detected');
        $this->assertSame(2,(clone $notices)->count());
        $this->assertDatabaseHas('account_notifications',[
            'user_id'=>$owner->id,
            'target_path'=>'/security-dashboard?alert='.$alert->id,
        ]);
        $this->assertDatabaseHas('account_notifications',['user_id'=>$second->id]);
        $this->assertDatabaseMissing('account_notifications',[
            'user_id'=>$staff->id,'event'=>'security.alert.detected',
        ]);
        $this->assertDatabaseMissing('account_notifications',[
            'user_id'=>$inactive->id,'event'=>'security.alert.detected',
        ]);
        $this->assertDatabaseCount('security_alerts',1);
    }

    public function test_low_counts_expired_events_and_disabled_rule_do_not_trigger(): void
    {
        $this->member();
        $rule=SecurityAlertRule::query()->create($this->payload([
            'threshold'=>3,'is_enabled'=>true,
        ]));
        $this->failures(5,30); // Outside the 15-minute observation window.
        $this->failures(2,2);
        $this->assertSame(0,app(SecurityAlertMonitor::class)->scan());
        $this->assertDatabaseCount('security_alerts',0);

        $rule->update(['is_enabled'=>false]);
        $this->failures(8);
        $this->assertSame(0,app(SecurityAlertMonitor::class)->scan());
        $this->assertDatabaseCount('security_alerts',0);
    }

    public function test_manual_scan_and_acknowledgement_are_real_and_audited(): void
    {
        $owner=$this->member();
        $rule=SecurityAlertRule::query()->create($this->payload(['threshold'=>1]));
        $this->failures(1);
        $this->actingAs($owner)->post(route('security.alerts.scan'))
            ->assertRedirect()->assertSessionHas('status');

        $alert=SecurityAlert::query()->firstOrFail();
        $this->get(route('security.index',['alert'=>$alert->id]))->assertOk()
            ->assertSee('Needs review')->assertSee('Repeated failed sign-ins');
        $this->patch(route('security.alerts.acknowledge',$alert))->assertRedirect();
        $this->assertSame($owner->id,$alert->fresh()->acknowledged_by);
        $this->assertNotNull($alert->fresh()->acknowledged_at);
        $this->assertDatabaseHas('audit_events',['event'=>'security.alert.acknowledged']);

        $this->patch(route('security.alerts.acknowledge',$alert))->assertRedirect();
        $this->assertSame(1,AuditEvent::query()
            ->where('event','security.alert.acknowledged')->count());
        $this->get(route('security.index'))->assertOk()->assertSee('Acknowledged');
    }

    public function test_deleting_rule_preserves_its_alert_history(): void
    {
        $owner=$this->member();
        $rule=SecurityAlertRule::query()->create($this->payload(['threshold'=>1]));
        $this->failures(1);
        app(SecurityAlertMonitor::class)->scan();

        $this->actingAs($owner)->delete(route('security.rules.destroy',$rule))->assertRedirect();
        $this->assertDatabaseCount('security_alert_rules',0);
        $alert=SecurityAlert::query()->firstOrFail();
        $this->assertNull($alert->security_alert_rule_id);
        $this->assertSame('Repeated failed sign-ins',$alert->rule_name);
        $this->assertDatabaseHas('audit_events',['event'=>'security.alert.rule.deleted']);
    }

    public function test_staff_and_other_users_cannot_view_or_modify_monitoring_rules(): void
    {
        $rule=SecurityAlertRule::query()->create($this->payload());
        $alert=SecurityAlert::query()->create([
            'security_alert_rule_id'=>$rule->id,'rule_name'=>$rule->name,
            'event'=>$rule->event,'observed_count'=>3,
            'observed_from'=>now()->subMinutes(10),'observed_until'=>now(),
        ]);
        foreach(['admin','staff','customer','freelancer'] as $role) {
            $this->actingAs($this->member($role))
                ->get(route('security.index'))->assertForbidden();
            $this->post(route('security.alerts.scan'))->assertForbidden();
            $this->post(route('security.rules.store'),$this->payload())->assertForbidden();
            $this->patch(route('security.rules.update',$rule),$this->payload())->assertForbidden();
            $this->delete(route('security.rules.destroy',$rule))->assertForbidden();
            $this->patch(route('security.alerts.acknowledge',$alert))->assertForbidden();
        }
        $this->assertDatabaseCount('security_alert_rules',1);
        $this->assertNull($alert->fresh()->acknowledged_at);
    }

    public function test_command_uses_stored_events_and_can_run_without_web_authentication(): void
    {
        $this->member();
        SecurityAlertRule::query()->create($this->payload(['threshold'=>1]));
        $this->failures(1);
        $this->assertSame(0,Artisan::call('security:alerts-scan'));
        $this->assertDatabaseCount('security_alerts',1);
    }
}
