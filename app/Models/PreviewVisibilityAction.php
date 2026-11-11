<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PreviewVisibilityAction extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'subject_kind', 'subject_id', 'subject_public_id', 'actor_id',
        'action', 'from_status', 'to_status', 'review_fingerprint',
        'owner_confirmation_reference', 'backup_reference',
        'rights_review_reference', 'reason',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * Audit decisions are append-only through Eloquent. Database-level access
     * controls and verified backups remain separate release requirements.
     */
    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('Preview visibility history is immutable.');
        });
        static::deleting(static function (): never {
            throw new \LogicException('Preview visibility history cannot be deleted.');
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
