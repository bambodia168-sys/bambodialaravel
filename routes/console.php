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
