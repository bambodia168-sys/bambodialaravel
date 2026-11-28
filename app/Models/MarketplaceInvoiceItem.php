<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MarketplaceInvoiceItem extends Model
{
    public $timestamps = false;
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['position'=>'integer','quantity'=>'integer','unit_amount'=>'decimal:2','line_total'=>'decimal:2'];
    }

    public function invoice(): BelongsTo { return $this->belongsTo(MarketplaceInvoice::class,'marketplace_invoice_id'); }
}
