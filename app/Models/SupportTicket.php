<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    use SoftDeletes;

    public const STATUSES = [
        'open' => 'Open',
        'pending' => 'Pending',
        'in_progress' => 'In Progress',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];

    protected $fillable = ['user_id', 'subject', 'status', 'context_type', 'context_id', 'assigned_to', 'issue_type'];

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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** Owners may revise/withdraw only an unhandled request. */
    public function ownerCanRevise(): bool
    {
        return in_array($this->status, ['open', 'pending'], true)
            && ! $this->trashed()
            && $this->messages()->count() === 1;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrder::class, 'context_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(SupportTicketCredential::class)->latest();
    }

    public function eraseSharedCredentials(): void
    {
        $this->credentials()->whereNotNull('ciphertext')
            ->update(['ciphertext' => null, 'revoked_at' => now(), 'updated_at' => now()]);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->oldest();
    }
}
