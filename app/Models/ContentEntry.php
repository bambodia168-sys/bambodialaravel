<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ContentEntry extends Model
{
    protected $fillable = ['type', 'title', 'body', 'body_format', 'status', 'author_id', 'published_at', 'excerpt', 'category', 'tags', 'seo_title', 'seo_description', 'featured_media_id', 'featured_type', 'featured_url', 'featured_color'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            $entry->slug = Str::slug($entry->title) ?: 'content';
            for ($i = 0; $i < 30; $i++) {
                $candidate = (string) random_int(100000000, 999999999);
                if (! self::query()->where('public_id', $candidate)->exists()) {
                    $entry->public_id = $candidate;
                    return;
                }
            }
            throw new \RuntimeException('Unable to allocate a content identifier.');
        });
        static::updating(function (self $entry): void {
            if ($entry->isDirty('title')) {
                $entry->slug = Str::slug($entry->title) ?: 'content';
            }
        });
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function featuredMedia(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'featured_media_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(PostComment::class, 'content_entry_id');
    }

    public function isPublic(): bool
    {
        return $this->status === 'published' && $this->published_at !== null;
    }

    public function publicUrl(): string
    {
        return url('/'.$this->public_id.'_'.$this->slug);
    }
}
