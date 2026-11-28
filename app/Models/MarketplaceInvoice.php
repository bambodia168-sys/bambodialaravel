<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MarketplaceInvoice extends Model
{
    public const STATUSES = ['draft','discarded','issued','void'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'subtotal'=>'decimal:2','total'=>'decimal:2','due_on'=>'date',
            'issued_at'=>'datetime','voided_at'=>'datetime','discarded_at'=>'datetime',
            'revision'=>'integer',
        ];
    }

    public function getRouteKeyName(): string { return 'public_id'; }
    public function order(): BelongsTo { return $this->belongsTo(MarketplaceOrder::class,'marketplace_order_id'); }
    public function issuer(): BelongsTo { return $this->belongsTo(User::class,'issuer_id'); }
    public function recipient(): BelongsTo { return $this->belongsTo(User::class,'recipient_id'); }
    public function items(): HasMany { return $this->hasMany(MarketplaceInvoiceItem::class)->orderBy('position'); }
}
