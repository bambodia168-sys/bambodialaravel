<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductFileVersion extends Model
{
    protected $fillable = [
        'marketplace_listing_id', 'uploaded_by', 'version', 'release_notes', 'original_filename',
        'storage_path', 'file_type', 'byte_size', 'sha256', 'status', 'rights_attested_at',
        'inspected_at', 'inspected_by', 'reviewed_at', 'reviewed_by', 'review_note',
    ];

    protected function casts(): array
    {
        return ['rights_attested_at' => 'datetime', 'inspected_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(MarketplaceListing::class, 'marketplace_listing_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(ProductEntitlement::class, 'product_file_version_id');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
