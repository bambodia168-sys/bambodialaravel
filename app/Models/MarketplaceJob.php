<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MarketplaceJob extends Model
{
    protected $fillable = ['client_id', 'title', 'description', 'description_html', 'gallery_media_ids', 'featured_kind', 'featured_media_id', 'featured_color', 'category', 'budget', 'currency', 'status', 'reviewed_by', 'reviewed_at', 'closed_at'];
    protected function casts(): array
    {
        return ['budget' => 'decimal:2', 'gallery_media_ids' => 'array', 'reviewed_at' => 'datetime', 'closed_at' => 'datetime'];
    }
    protected static function booted(): void
    {
        static::creating(function (self $job): void {
            $job->slug = Str::slug($job->title) ?: 'job';
            for ($i = 0; $i < 30; $i++) {
                $id = (string) random_int(100000000, 999999999);
                if (! self::query()->where('public_id', $id)->exists()) {
                    $job->public_id = $id;
                    return;
                }
            }
            throw new \RuntimeException('Unique job ID could not be allocated.');
        });
    }
    public function savedBy(): HasMany
    {
        return $this->hasMany(SavedJob::class,'marketplace_job_id');
    }

    public function featuredAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'featured_media_id');
    }

    public function client(): BelongsTo { return $this->belongsTo(User::class, 'client_id'); }
    public function proposals(): HasMany { return $this->hasMany(JobProposal::class, 'marketplace_job_id'); }
    public function publicUrl(): string { return route('jobs.show', $this->public_id.'-'.$this->slug); }
}
