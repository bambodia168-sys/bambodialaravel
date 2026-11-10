<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SecurityAlertRule extends Model
{
    /**
     * Events are already recorded in the existing audit ledger; this is not
     * a malware scanner or a claim that activity is necessarily malicious.
     */
    public const MONITORED_EVENTS = [
        'auth.login.failed' => 'Failed sign-in attempts',
        'auth.two_factor.challenge.failed' => 'Failed two-factor challenges',
        'user.role.changed' => 'Account role changes',
        'developer.token.created' => 'Developer API tokens created',
        'security.turnstile.status.changed' => 'Bot protection settings changed',
        'security.turnstile.removed' => 'Bot protection disconnected',
        'cloud.upload_mode.changed' => 'Cloud upload destination changed',
        'user.account.invalidated' => 'Account verification invalidated',
    ];

    public const WINDOWS = [5, 15, 60, 240];
    public const COOLDOWNS = [15, 60, 240, 1440];

    protected $fillable = [
        'name','event','threshold','window_minutes','cooldown_minutes',
        'is_enabled','last_checked_at','last_triggered_at',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'threshold' => 'integer',
            'window_minutes' => 'integer',
            'cooldown_minutes' => 'integer',
            'last_checked_at' => 'datetime',
            'last_triggered_at' => 'datetime',
        ];
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(SecurityAlert::class);
    }
}
