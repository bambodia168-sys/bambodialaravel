<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercePayment extends Model
{
    public const QR_PROVIDERS = ['aba', 'bakong'];

    public const ACTIVE = ['starting', 'checkout_open', 'manual_review'];
    public const LABELS = [
        'starting'=>'Starting checkout', 'checkout_open'=>'Checkout open', 'manual_review'=>'Pending review',
        'paid'=>'Verified', 'rejected'=>'Rejected', 'expired'=>'Expired',
    ];

    protected $fillable = [
        'number', 'commerce_payment_method_id', 'provider', 'method_name', 'live_mode', 'status', 'remote_id',
        'settlement_reference', 'submitted_reference', 'proof_path', 'reviewed_by', 'review_note', 'reviewed_at',
        'qr_payload', 'qr_expires_at',
    ];

    protected function casts(): array
    {
        return ['live_mode'=>'boolean', 'reviewed_at'=>'datetime', 'qr_expires_at'=>'datetime'];
    }

    public function dynamicQr(): bool
    {
        return ! $this->proof_path && in_array($this->provider, self::QR_PROVIDERS, true);
    }

    public function qrVisible(): bool
    {
        return $this->status === 'checkout_open' && $this->qr_payload && $this->qr_expires_at?->isFuture();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id');
    }
}
