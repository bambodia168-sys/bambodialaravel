<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class EmailCampaign extends Model
{
    protected $fillable = [
        'event', 'title', 'message', 'target_path', 'created_by',
        'last_user_id', 'audience_max_id', 'queued_at', 'expanded_at',
    ];

    protected function casts(): array
    {
        return ['queued_at' => 'datetime', 'expanded_at' => 'datetime'];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(EmailDelivery::class);
    }
}
