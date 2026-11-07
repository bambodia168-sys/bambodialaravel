<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class LicenseDefinition extends Model
{
    public const TIERS = ['regular'=>'Regular License','extended'=>'Extended License'];

    protected $fillable = [
        'code', 'tier', 'name', 'version', 'permissions', 'restrictions',
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

    /** Existing unclassified definitions remain usable until administrators classify them.
     * The label is guidance, not an inferred grant of extra commercial rights. */
    public function tierLabel(): string
    {
        return self::TIERS[$this->tier] ?? 'Legacy definition (unclassified)';
    }

    public static function tierDescription(string $tier): string
    {
        return $tier === 'extended'
            ? 'May provide additional commercial or monetized usage only when the published Extended License explicitly allows it. Original files remain protected.'
            : 'Typically intended for a single end project or installation, strictly subject to the approved Regular License terms and restrictions.';
    }

    public function allowsTier(string $tier): bool
    {
        // Legacy definitions predating tier classification retain their existing
        // references; new typed definitions are never interchangeable.
        return $this->tier === null || $this->tier === $tier;
    }

    public function publicUrl(): string
    {
        return url('/licenses').'?='.$this->public_id.'-'.$this->slug;
    }
}
