<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductEntitlement extends Model
{
    protected $fillable = [
        'user_id', 'product_file_version_id', 'granted_by', 'grant_reason', 'status',
        'expires_at', 'revoked_at', 'revoked_by', 'revocation_reason',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ProductFileVersion::class, 'product_file_version_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ProductEntitlementAction::class, 'product_entitlement_id')->latest('id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
