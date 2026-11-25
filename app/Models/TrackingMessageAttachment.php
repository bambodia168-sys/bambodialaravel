<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class TrackingMessageAttachment extends Model
{
    protected $fillable = [
        'marketplace_order_id', 'service_inquiry_message_id', 'uploaded_by', 'storage_path',
        'original_name', 'mime_type', 'bytes', 'sha256', 'scan_status',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $attachment) => $attachment->public_id ??= (string) Str::uuid());
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ServiceInquiryMessage::class, 'service_inquiry_message_id');
    }
}
