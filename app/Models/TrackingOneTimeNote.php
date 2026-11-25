<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class TrackingOneTimeNote extends Model
{
    protected $fillable = [
        'marketplace_order_id', 'created_by', 'recipient_id', 'label',
        'ciphertext', 'expires_at', 'opened_at', 'revoked_at',
    ];

    protected $hidden = ['ciphertext'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'opened_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $note) => $note->public_id ??= (string) Str::uuid());
    }

    public function isAvailable(): bool
    {
        return $this->opened_at === null && $this->revoked_at === null
            && $this->expires_at !== null && $this->expires_at->isFuture()
            && $this->ciphertext !== null;
    }
}
