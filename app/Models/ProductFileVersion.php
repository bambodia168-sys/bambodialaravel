<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\VaultReleaseGate;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductFileVersion extends Model
{
    protected $fillable = [
        'marketplace_listing_id', 'uploaded_by', 'version', 'release_notes', 'original_filename',
        'storage_path', 'file_type', 'byte_size', 'sha256', 'status', 'rights_attested_at',
        'inspected_at', 'inspected_by', 'reviewed_at', 'reviewed_by', 'review_note',
        'rights_evidence','rights_source_url','rights_review_status','rights_reviewed_by',
        'rights_reviewed_at','rights_review_note','malware_scan_status','malware_scanned_sha256',
        'malware_scan_engine','malware_scanned_at','malware_scanned_by','malware_scan_note',
    ];

    protected function casts(): array
    {
        return [
            'rights_attested_at'=>'datetime','inspected_at'=>'datetime','reviewed_at'=>'datetime',
            'rights_reviewed_at'=>'datetime','malware_scanned_at'=>'datetime',
        ];
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
        return VaultReleaseGate::deliverable($this);
    }
}
