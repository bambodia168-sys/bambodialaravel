<?php

use App\Models\AuditEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('audit:prune {--days=90}', function (): int {
    $days = (int) $this->option('days');
    if ($days < 30 || $days > 365) {
        $this->error('Retention must be between 30 and 365 days.');

        return 1;
    }

    $deleted = AuditEvent::query()->where('created_at', '<', now()->subDays($days))->delete();
    $this->info($deleted.' expired audit entries deleted.');

    return 0;
})->purpose('Delete audit records older than the configured retention period');

Schedule::command('audit:prune --days=90')->dailyAt('03:15')->withoutOverlapping();


// Local shell/aaPanel recovery for a broken third-party bot verification provider.
// Not exposed through web routes and deliberately never prints private keys.
Artisan::command('security:turnstile-disable', function (): int {
    $settings = \App\Models\TurnstileBotProtection::current();
    if (! $settings || ! $settings->is_enabled) {
        $this->info('Turnstile is already disabled.');
        return 0;
    }
    $settings->update(['is_enabled' => false]);
    \App\Models\AuditEvent::record(
        'security.turnstile.status.changed',
        null,
        \Illuminate\Http\Request::create('/artisan/security/turnstile-disable','POST')
    );
    $this->info('Turnstile disabled. Existing login rate limits remain active.');
    return 0;
})->purpose('Emergency recovery: disable Cloudflare Turnstile via authenticated server shell');


// Real event thresholds and in-app notification delivery. No external traffic,
// no raw IP logs, and no malware/intrusion-detection claims.
Artisan::command('security:alerts-scan', function (): int {
    $count = app(\App\Support\Security\SecurityAlertMonitor::class)->scan();
    $this->info($count.' security alerts created from recorded audit events.');
    return 0;
})->purpose('Evaluate configured security audit alert rules and notify super-admins');

Schedule::command('security:alerts-scan')->everyMinute()->withoutOverlapping();
