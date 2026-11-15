<?php

namespace App\Models;

use App\Support\LicenseTerms;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class LicenseDefinition extends Model
{
    public const TIERS = [
        'regular' => 'Regular License',
        'extended' => 'Extended License',
        'agency' => 'Agency / Multi-Site License',
        'evaluation' => 'Evaluation / Trial License',
        'media' => 'Media / Broadcast License',
        'oem' => 'OEM / Redistribution License',
    ];

    private const LIVE_LAYOUT_DEFINITION = '184752080';
    private const LIVE_LAYOUT_PRODUCTS = ['487948714', '673785514', '381502999', '792285328', '140274590'];

    protected $fillable = [
        'code', 'tier', 'name', 'version', 'permissions', 'restrictions',
        'permissions_html', 'restrictions_html',
        'status', 'published_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $license): void {
            $license->slug = Str::slug($license->name) ?: 'license';
            for ($attempt = 0; $attempt < 30; $attempt++) {
                $candidate = (string) random_int(100000000, 999999999);
                if (! self::query()->where('public_id', $candidate)->exists()) {
                    $license->public_id = $candidate;
                    return;
                }
            }
            throw new \RuntimeException('Unable to allocate a public license identifier.');
        });
        static::updating(function (self $license): void {
            if ($license->isDirty('name')) {
                $license->slug = Str::slug($license->name) ?: 'license';
            }
        });
    }

    public function listings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class, 'license_definition_id');
    }

    public function extendedListings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class, 'extended_license_definition_id');
    }

    public function issuedLicenses(): HasMany
    {
        return $this->hasMany(ProductLicense::class, 'license_definition_id');
    }

    public function hasReferences(): bool
    {
        return $this->listings()->exists() || $this->extendedListings()->exists() || $this->issuedLicenses()->exists();
    }

    public function isLayoutTest(): bool
    {
        return $this->code === 'LAYOUT_TEST_ONLY' && $this->name === 'Layout Test Only — No Commercial License';
    }

    public function canArchiveLayoutTest(): bool
    {
        if (! $this->isLayoutTest() || $this->status === 'archived'
            || $this->issuedLicenses()->exists() || $this->extendedListings()->exists()) {
            return false;
        }

        $listings = $this->listings();
        $unverified = (clone $listings)->where(function ($query): void {
            $query->where('type', '!=', 'product')
                ->orWhere('title', 'not like', '[LAYOUT TEST] %')
                ->orWhere(function ($fixture): void {
                    // Match the original production products even when their creator account changes.
                    if ((string) $this->public_id === self::LIVE_LAYOUT_DEFINITION) {
                        $fixture->whereNotIn('public_id', self::LIVE_LAYOUT_PRODUCTS)
                            ->orWhere('description', 'not like', 'LAYOUT TEST ONLY%');
                    } else {
                        $fixture->whereDoesntHave('creator', fn ($creator) =>
                            $creator->where('email', 'layout-preview-creator@bambodia.invalid'));
                    }
                });
        });
        if ($unverified->exists()) {
            return false;
        }

        // The original layout samples have no downloadable release or license history.
        $ids = $listings->select('marketplace_listings.id');
        return ! ProductFileVersion::query()->whereIn('marketplace_listing_id', $ids)->exists()
            && ! ProductLicense::query()->whereIn('marketplace_listing_id', $ids)->exists();
    }

    public function permissionsMarkup(): string
    {
        return LicenseTerms::render($this->permissions, $this->permissions_html);
    }

    public function restrictionsMarkup(): string
    {
        return LicenseTerms::render($this->restrictions, $this->restrictions_html);
    }

    /** Existing unclassified definitions remain usable until administrators classify them.
     * The label is guidance, not an inferred grant of extra commercial rights. */
    public function tierLabel(): string
    {
        return self::TIERS[$this->tier] ?? 'Legacy definition (unclassified)';
    }

    public static function tierDescription(string $tier): string
    {
        return match ($tier) {
            'regular' => 'Typically intended for a single end project or installation, strictly subject to the approved Regular License terms and restrictions.',
            'extended' => 'May provide additional commercial or monetized usage only when the published Extended License explicitly allows it. Original files remain protected.',
            'agency' => 'Covers a stated number of independent projects under the license tier specified for each project.',
            'evaluation' => 'Allows time-limited, private testing under an evaluation grant.',
            'media' => 'Covers the approved use of a media asset in a production, with distribution limited to the agreed scope.',
            'oem' => 'Sets the conditions for distributing approved components as part of a specified product under a separate written agreement.',
            default => '',
        };
    }

    public function allowsTier(string $tier): bool
    {
        // Legacy definitions predating tier classification retain their existing
        // references; new typed definitions are never interchangeable.
        return in_array($tier, ['regular', 'extended'], true)
            && $this->status !== 'archived'
            && ($this->tier === null || $this->tier === $tier);
    }

    public function publicUrl(): string
    {
        return url('/licenses').'?='.$this->public_id.'-'.$this->slug;
    }
}
