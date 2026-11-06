<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProductLicense extends Model
{
    protected $fillable = [
        'product_entitlement_id', 'marketplace_listing_id', 'product_file_version_id',
        'user_id', 'license_definition_id', 'issued_by', 'tier', 'source',
        'key_hash', 'key_prefix', 'key_ciphertext', 'status', 'max_domains',
        'expires_at', 'suspended_at', 'revoked_at',
    ];

    protected $hidden = ['key_hash','key_ciphertext'];

    protected function casts(): array
    {
        return [
            'key_ciphertext' => 'encrypted',
            'expires_at' => 'datetime', 'suspended_at' => 'datetime', 'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $license): void {
            $license->public_id ??= (string) Str::uuid();
        });
    }

    public function entitlement(): BelongsTo { return $this->belongsTo(ProductEntitlement::class, 'product_entitlement_id'); }
    public function product(): BelongsTo { return $this->belongsTo(MarketplaceListing::class, 'marketplace_listing_id'); }
    public function version(): BelongsTo { return $this->belongsTo(ProductFileVersion::class, 'product_file_version_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function definition(): BelongsTo { return $this->belongsTo(LicenseDefinition::class, 'license_definition_id'); }
    public function issuer(): BelongsTo { return $this->belongsTo(User::class, 'issued_by'); }
    public function activations(): HasMany { return $this->hasMany(ProductLicenseActivation::class); }
    public function events(): HasMany { return $this->hasMany(ProductLicenseEvent::class)->latest('id'); }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && $this->entitlement?->isActive() === true;
    }

    public function maskedKey(): string
    {
        return $this->key_prefix.'••••••••';
    }
}
