<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MarketplaceOrder extends Model
{
    public const STATUSES = [
        'awaiting_payment' => 'Awaiting payment',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'refunded' => 'Refunded',
    ];

    protected $fillable = [
        'number', 'buyer_id', 'seller_id', 'marketplace_listing_id', 'request_key',
        'listing_title', 'buyer_name', 'seller_name', 'license_tier', 'license_name',
        'fulfillment_version_id', 'fulfillment_definition_id', 'fulfillment_terms_hash',
        'total', 'currency', 'payment_method', 'status', 'receipt_number', 'completed_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return ['total' => 'decimal:2', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CommercePayment::class);
    }

    public function entitlement(): HasOne
    {
        return $this->hasOne(ProductEntitlement::class);
    }

    public function paidAttempt(): HasOne
    {
        return $this->hasOne(CommercePayment::class)->where('status', 'paid')->whereNotNull('settlement_reference');
    }

    public function operations(): HasOne
    {
        return $this->hasOne(CommerceOrderOperation::class);
    }
}
