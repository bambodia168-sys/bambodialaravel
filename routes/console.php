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


/**
 * Read-only publication inventory. Safe for an operator to run in production,
 * unlike QA seeders, moderating commands or content cleanup scripts.
 */
Artisan::command('marketplace:readiness-audit {--json : Machine-readable report} {--strict : Exit 2 when editorial review is needed}', function (): int {
    $report = \App\Support\MarketplaceReadinessAudit::inspect();

    if ($this->option('json')) {
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    } else {
        $this->info('Bambodia public content readiness: '.$report['status']);
        foreach ($report['counts'] as $name => $count) {
            $this->line($name.'='.$count);
        }
        foreach ($report['preview_markers'] as $name => $count) {
            $this->line('preview_markers.'.$name.'='.$count);
        }
        foreach ($report['samples'] as $sample) {
            $this->warn($sample['kind'].' '.$sample['public_id'].': '.$sample['title']);
        }
        foreach ($report['notices'] as $notice) {
            $this->warn($notice['code'].': '.$notice['message']);
        }
        if ($report['sample_truncated']) {
            $this->warn('Only the first 25 marked public records are shown; totals include all matches.');
        }
        $this->line('No records were changed.');
    }

    return $this->option('strict') && $report['status'] !== 'pass' ? 2 : 0;
})->purpose('Report publicly visible preview titles and Knowledge Base readiness; never modify content');


/**
 * Read-only owner/dependency assessment for published preview-marked inventory.
 * This command never withdraws, updates, archives or deletes marketplace data.
 */
Artisan::command('marketplace:preview-review {--json : Print a structured review report} {--strict : Return 2 when any preview item requires manual review}', function (): int {
    $report = \App\Support\PreviewContentReview::inspect();

    if ($this->option('json')) {
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    } else {
        $this->info('Marketplace preview review: '.$report['status']);
        foreach ($report['counts'] as $name => $count) {
            $this->line($name.'='.$count);
        }
        foreach ($report['records'] as $record) {
            $this->warn($record['kind'].' '.$record['public_id'].' '.$record['review_status']);
        }
        if ($report['sample_truncated']) {
            $this->warn('Only the first 50 records were included; totals cover all public preview records.');
        }
        $this->line('No listing, job, customer, file or publication record was changed.');
    }

    return $this->option('strict') && $report['status'] !== 'no_preview_markers' ? 2 : 0;
})->purpose('Read-only review of public preview item ownership, media and customer activity');
