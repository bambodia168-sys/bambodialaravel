<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MarketplaceOrder extends Model
{
    public const STATUSES = [
        'awaiting_payment' => 'Awaiting payment',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
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
        return $this->hasOne(CommercePayment::class)->where('status', 'paid')->whereNotNull('settlement_reference')
            ->when(app()->isProduction(), fn ($payment) => $payment->where('live_mode', true));
    }

    public function serviceInquiry(): HasOne
    {
        return $this->hasOne(ServiceInquiry::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(MarketplaceOrderReview::class);
    }

    public function scopeReviewEligible(Builder $orders): Builder
    {
        return $orders->where('status', 'completed')->whereHas('paidAttempt')
            ->where(fn (Builder $order) => $order->whereHas('entitlement')
                ->orWhereHas('serviceInquiry', fn (Builder $inquiry) => $inquiry->where('delivery_status', 'accepted')));
    }

    public function isReviewEligible(): bool
    {
        return self::query()->whereKey($this->id)->reviewEligible()->exists();
    }

    public function operations(): HasOne
    {
        return $this->hasOne(CommerceOrderOperation::class);
    }
}
