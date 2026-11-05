<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MarketplaceListing extends Model
{
    public const TYPES = ['product', 'project', 'freelance', 'service'];
    public const STATUSES = ['pending', 'published', 'rejected'];

    protected $fillable = ['user_id', 'type', 'title', 'description', 'price', 'currency', 'license_terms', 'status'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $listing): void {
            $listing->slug = Str::slug($listing->title) ?: 'listing';
            if ($listing->public_id) {
                return;
            }
            for ($i = 0; $i < 30; $i++) {
                $candidate = (string) random_int(100000000, 999999999);
                if (! self::query()->where('public_id', $candidate)->exists()) {
                    $listing->public_id = $candidate;
                    return;
                }
            }
            throw new \RuntimeException('Unable to allocate a listing identifier.');
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function publicUrl(): string
    {
        return url('/'.$this->type).'?='.$this->public_id.'-'.$this->slug;
    }
}
