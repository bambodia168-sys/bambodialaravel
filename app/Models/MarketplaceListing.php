<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MarketplaceListing extends Model
{
    public const TYPES = ['product', 'project', 'freelance', 'service'];
    public const STATUSES = ['draft', 'pending', 'published', 'rejected'];

    protected $fillable = ['user_id', 'type', 'title', 'description', 'description_html', 'featured_kind', 'featured_media_id', 'featured_color', 'price', 'software_version', 'software_published_on', 'software_updated_on', 'regular_price', 'extended_price', 'extended_license_definition_id', 'gallery_media_ids', 'currency', 'license_terms', 'license_definition_id', 'cover_media_id', 'category_id', 'status'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'regular_price' => 'decimal:2', 'extended_price' => 'decimal:2', 'gallery_media_ids' => 'array', 'software_published_on' => 'date', 'software_updated_on' => 'date'];
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

    public function scopePubliclyAvailable(Builder $query): Builder
    {
        return $query->where('status','published')
            ->where(function(Builder $q): void {
                $q->where('type','!=','product')->orWhere(function(Builder $products): void {
                    $products->where('type','product')
                        ->whereHas('licenseDefinition',fn(Builder $license)=>$license->where('status','active'))
                        ->whereHas('coverAsset');
                });
            });
    }

    public function savedBy(): HasMany
    {
        return $this->hasMany(SavedListing::class,'marketplace_listing_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MarketplaceCategory::class,'category_id');
    }

    public function extendedLicenseDefinition(): BelongsTo
    {
        return $this->belongsTo(LicenseDefinition::class, 'extended_license_definition_id');
    }

    public function licenseDefinition(): BelongsTo
    {
        return $this->belongsTo(LicenseDefinition::class, 'license_definition_id');
    }

    public function featuredAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'featured_media_id');
    }

    public function coverAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'cover_media_id');
    }

    public function publicUrl(): string
    {
        return url('/'.$this->type).'?='.$this->public_id.'-'.$this->slug;
    }
}
