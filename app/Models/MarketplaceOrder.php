<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
        'total', 'currency', 'payment_method', 'status', 'receipt_number', 'completed_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return ['total' => 'decimal:2', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }
}
