<?php

namespace App\Support\Security;

use App\Models\AccountNotification;
use App\Models\AuditEvent;
use App\Models\SecurityAlert;
use App\Models\SecurityAlertRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SecurityAlertMonitor
{
    /**
     * Evaluate existing real audit entries; never fabricate security findings.
     * Row locking prevents concurrent scheduled runs issuing duplicate alerts.
     */
    public function scan(): int
    {
        $created = 0;

        foreach (SecurityAlertRule::query()->where('is_enabled', true)->pluck('id') as $id) {
            DB::transaction(function () use ($id, &$created): void {
                $rule = SecurityAlertRule::query()->lockForUpdate()->find($id);
                if (! $rule || ! $rule->is_enabled
                    || ! array_key_exists($rule->event, SecurityAlertRule::MONITORED_EVENTS)) {
                    return;
                }

                $now = now();
                $windowStart = $now->copy()->subMinutes($rule->window_minutes);
                $count = AuditEvent::query()
                    ->where('event', $rule->event)
                    ->whereBetween('created_at', [$windowStart, $now])
                    ->count();

                $rule->last_checked_at = $now;
                $eligible = $count >= $rule->threshold
                    && ($rule->last_triggered_at === null
                        || $rule->last_triggered_at->lte(
                            $now->copy()->subMinutes($rule->cooldown_minutes)
                        ));

                if (! $eligible) {
                    $rule->save();
                    return;
                }

                $alert = SecurityAlert::query()->create([
                    'security_alert_rule_id' => $rule->id,
                    'rule_name' => $rule->name,
                    'event' => $rule->event,
                    'observed_count' => $count,
                    'observed_from' => $windowStart,
                    'observed_until' => $now,
                ]);

                $rule->last_triggered_at = $now;
                $rule->save();

                $title = 'Security alert: '.$rule->name;
                $message = number_format($count).' recorded '.($count === 1 ? 'event' : 'events')
                    .' in the past '.$rule->window_minutes.' minutes. Review the audit log.';

                User::query()->where('role', 'super_admin')
                    ->where('account_status', 'active')
                    ->orderBy('id')->select('id')->chunkById(100, function ($owners) use ($title, $message, $alert): void {
                        foreach ($owners as $owner) {
                            AccountNotification::deliver(
                                $owner->id, 'security.alert.detected',
                                $title, $message,
                                '/security-dashboard?alert='.$alert->id
                            );
                        }
                    });

                AuditEvent::record(
                    'security.alert.triggered',
                    null,
                    \Illuminate\Http\Request::create('/artisan/security/alerts-scan', 'POST')
                );
                $created++;
            }, 3);
        }

        return $created;
    }
}
