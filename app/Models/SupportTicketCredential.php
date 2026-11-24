<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SupportTicketCredential extends Model
{
    protected $fillable = ['support_ticket_id', 'created_by', 'label', 'ciphertext', 'expires_at', 'revoked_at'];

    protected $hidden = ['ciphertext'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime', 'revoked_at' => 'datetime',
            'last_viewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $model) => $model->public_id ??= (string) Str::uuid());
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function active(): bool
    {
        return $this->ciphertext !== null && $this->revoked_at === null
            && $this->expires_at?->isFuture();
    }
}
