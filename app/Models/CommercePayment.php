<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercePayment extends Model
{
    public const ACTIVE = ['starting', 'checkout_open', 'manual_review'];
    public const LABELS = [
        'starting'=>'Starting checkout', 'checkout_open'=>'Checkout open', 'manual_review'=>'Pending review',
        'paid'=>'Verified', 'rejected'=>'Rejected', 'expired'=>'Expired',
    ];

    protected $fillable = [
        'number', 'commerce_payment_method_id', 'provider', 'method_name', 'live_mode', 'status', 'remote_id',
        'settlement_reference', 'submitted_reference', 'proof_path', 'reviewed_by', 'review_note', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['live_mode'=>'boolean', 'reviewed_at'=>'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id');
    }
}
