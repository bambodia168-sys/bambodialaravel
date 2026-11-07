<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyUpdateNotice extends Model
{
    protected $fillable = [
        'policy_id', 'policy_publication_id', 'headline', 'message', 'notify_account',
        'notify_email', 'show_banner', 'starts_at', 'ends_at', 'queued_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'notify_account' => 'boolean',
            'notify_email' => 'boolean',
            'show_banner' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'queued_at' => 'datetime',
        ];
    }

    public function policy(): BelongsTo { return $this->belongsTo(Policy::class); }
    public function publication(): BelongsTo { return $this->belongsTo(PolicyPublication::class, 'policy_publication_id'); }
}
