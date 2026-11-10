<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SecurityAlert extends Model
{
    protected $fillable = [
        'security_alert_rule_id','rule_name','event','observed_count',
        'observed_from','observed_until','acknowledged_at','acknowledged_by',
    ];

    protected function casts(): array
    {
        return [
            'observed_count' => 'integer',
            'observed_from' => 'datetime',
            'observed_until' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(SecurityAlertRule::class, 'security_alert_rule_id');
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
