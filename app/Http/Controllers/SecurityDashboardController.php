<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\SecurityAlert;
use App\Models\SecurityAlertRule;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SecurityDashboardController extends Controller
{
    public function index(Request $request): View
    {
        $type = (string) $request->query('event', '');
        abort_if($type !== '' && ! in_array($type, AuditEvent::EVENTS, true), 404);

        $events = AuditEvent::query()->with('actor:id,name,public_id')
            ->when($type !== '', fn ($builder) => $builder->where('event', $type))
            ->latest('id')->paginate(10, ['*'], 'audit_page')->withQueryString();

        $since = now()->subDay();
        $rules = SecurityAlertRule::query()->orderBy('id')
            ->paginate(10, ['*'], 'rule_page')->withQueryString();
        $rules->getCollection()->each(function (SecurityAlertRule $rule): void {
            $rule->observed_count = AuditEvent::query()
                ->where('event', $rule->event)
                ->where('created_at', '>=', now()->subMinutes($rule->window_minutes))
                ->count();
        });
        $alerts = SecurityAlert::query()->with('acknowledger:id,name')
            ->latest('id')->paginate(10, ['*'], 'alert_page')->withQueryString();

        return view('security.index', [
            'events' => $events,
            'alertRules' => $rules,
            'alerts' => $alerts,
            'monitorEvents' => SecurityAlertRule::MONITORED_EVENTS,
            'monitorWindows' => SecurityAlertRule::WINDOWS,
            'monitorCooldowns' => SecurityAlertRule::COOLDOWNS,
            'openAlerts' => SecurityAlert::query()->whereNull('acknowledged_at')->count(),
            'alertsToday' => SecurityAlert::query()->where('created_at', '>=', $since)->count(),
            'lastSecurityScan' => SecurityAlertRule::query()->max('last_checked_at'),
            'eventFilter' => $type,
            'availableEvents' => AuditEvent::EVENTS,
            'eventsToday' => AuditEvent::query()->where('created_at', '>=', $since)->count(),
            'failedLoginsToday' => AuditEvent::query()->where('event', 'auth.login.failed')->where('created_at', '>=', $since)->count(),
            'activeTokens' => ApiToken::query()->whereNull('revoked_at')->where('expires_at', '>', now())->count(),
        ]);
    }
}
