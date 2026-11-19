<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommerceOrderOperation extends Model
{
    protected $fillable = [
        'commerce_payment_id', 'refund_status', 'refund_reason', 'refund_note', 'refund_requested_at',
        'refund_resolved_at', 'refund_reference', 'statement_reference', 'seller_payable', 'reconciled_at',
        'refund_access', 'refund_confirmed_by',
        'payout_reference', 'paid_out_at', 'recovery_reference', 'recovered_at',
    ];

    protected function casts(): array
    {
        return [
            'seller_payable' => 'decimal:2', 'refund_requested_at' => 'datetime',
            'refund_resolved_at' => 'datetime', 'reconciled_at' => 'datetime',
            'paid_out_at' => 'datetime', 'recovered_at' => 'datetime',
        ];
    }

    public function recoveryDue(): bool
    {
        return $this->refund_status === 'refunded' && $this->paid_out_at !== null && $this->recovered_at === null;
    }
}
