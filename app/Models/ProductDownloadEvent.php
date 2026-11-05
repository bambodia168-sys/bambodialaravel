<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductDownloadEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['product_entitlement_id', 'user_id', 'product_file_version_id', 'sha256', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(ProductEntitlement::class, 'product_entitlement_id');
    }
}
