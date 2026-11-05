<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaAsset extends Model
{
    public const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    protected $fillable = [
        'user_id', 'original_name', 'storage_path', 'mime_type', 'bytes',
        'width', 'height', 'alt_text', 'rights_basis', 'rights_holder',
        'rights_note', 'review_status', 'review_note', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $asset): void {
            for ($attempt = 0; $attempt < 30; $attempt++) {
                $candidate = (string) random_int(100000000, 999999999);
                if (! self::query()->where('public_id', $candidate)->exists()) {
                    $asset->public_id = $candidate;
                    return;
                }
            }
            throw new \RuntimeException('Unable to allocate a media public identifier.');
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function coverListings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class, 'cover_media_id');
    }

    public function isApproved(): bool
    {
        return $this->review_status === 'approved';
    }

    public function previewUrl(): string
    {
        return route('media.preview', $this->public_id);
    }
}
