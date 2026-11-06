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
        'key_hash', 'key_prefix', 'key_ciphertext', 'status', 'max_domains', 'pending_domain',
        'expires_at', 'suspended_at', 'revoked_at',
        'support_term', 'support_started_at', 'support_expires_at',
    ];

    protected $hidden = ['key_hash','key_ciphertext'];

    protected function casts(): array
    {
        return [
            'key_ciphertext' => 'encrypted',
            'expires_at' => 'datetime', 'suspended_at' => 'datetime', 'revoked_at' => 'datetime',
            'support_started_at'=>'datetime', 'support_expires_at'=>'datetime',
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
    public function supportRequests(): HasMany { return $this->hasMany(ProductLicenseSupportRequest::class)->latest('id'); }

    public function supportStatus(): string
    {
        if ($this->support_term === null) return 'Not set';
        if ($this->support_term === 'lifetime') return 'Lifetime';
        return $this->support_expires_at && $this->support_expires_at->isFuture() ? 'Active' : 'Expired';
    }

    public function supportName(): string
    {
        return \App\Support\ProductLicenseSupport::TERMS[$this->support_term] ?? 'Not set';
    }

    public function isActive(): bool
    {
        // Installable product licenses are perpetual. The original file
        // entitlement may independently limit future downloads, but its
        // time limit does not invalidate an already issued installation key.
        // Explicit revocation or suspension remains immediately effective.
        $entitlement=$this->entitlement;
        return $this->status === 'active' && $this->revoked_at === null
            && $entitlement !== null && $entitlement->status === 'active'
            && $entitlement->revoked_at === null;
    }

    public function maskedKey(): string
    {
        return $this->key_prefix.'••••••••';
    }
}
