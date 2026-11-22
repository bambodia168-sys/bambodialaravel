<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EmailDelivery extends Model
{
    protected $fillable = ['event', 'user_id', 'email_campaign_id', 'deduplication_key', 'status', 'context', 'sent_at'];

    protected function casts(): array
    {
        return ['context' => 'array', 'sent_at' => 'datetime'];
    }

    public function retryFailed(): bool
    {
        return self::query()->whereKey($this->id)->where('status', 'failed')->update(['status' => 'queued']) === 1;
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
