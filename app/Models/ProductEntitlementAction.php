<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductEntitlementAction extends Model
{
    public $timestamps = false;

    protected $fillable = ['product_entitlement_id', 'actor_id', 'action', 'reason', 'access_expires_at', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'access_expires_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(ProductEntitlement::class, 'product_entitlement_id');
    }
}
