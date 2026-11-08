<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    protected $fillable = ['user_id', 'subject', 'status', 'context_type', 'context_id'];

    protected static function booted(): void
    {
        static::creating(function (self $ticket): void {
            for ($attempt = 0; $attempt < 30; $attempt++) {
                $candidate = (string) random_int(100000000, 999999999);
                if (! self::query()->where('public_id', $candidate)->exists()) {
                    $ticket->public_id = $candidate;
                    return;
                }
            }
            throw new \RuntimeException('Unable to create a support ticket identifier.');
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->oldest();
    }
}
