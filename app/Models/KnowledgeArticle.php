<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class KnowledgeArticle extends Model
{
    protected $fillable = [
        'public_code', 'slug', 'title', 'excerpt', 'category', 'body',
        'body_format', 'status', 'author_id', 'reviewed_by', 'published_at',
    ];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $article): void {
            $article->slug = Str::slug($article->title) ?: 'article';
            if ($article->public_code) return;
            for ($i = 0; $i < 100; $i++) {
                $code = (string) random_int(10000000, 99999999);
                if (! self::query()->where('public_code', $code)->exists()) {
                    $article->public_code = $code;
                    return;
                }
            }
            throw new \RuntimeException('Unable to assign a unique knowledge article ID.');
        });
        static::updating(function (self $article): void {
            if ($article->isDirty('title')) {
                $article->slug = Str::slug($article->title) ?: 'article';
            }
        });
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function publicUrl(): string
    {
        return url('/support-center/articles/'.$this->public_code.'-'.$this->slug);
    }
}
