<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceOrderReview extends Model
{
    protected $fillable = ['rating', 'body', 'hidden_at'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'hidden_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id');
    }
}
