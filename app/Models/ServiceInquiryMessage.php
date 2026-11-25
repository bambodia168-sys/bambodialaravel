<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceInquiryMessage extends Model
{
    protected $fillable = ['service_inquiry_id','sender_id','body','content_format'];
    public function inquiry(): BelongsTo { return $this->belongsTo(ServiceInquiry::class,'service_inquiry_id'); }
    public function sender(): BelongsTo { return $this->belongsTo(User::class,'sender_id'); }
    public function trackingAttachments(): HasMany { return $this->hasMany(TrackingMessageAttachment::class, 'service_inquiry_message_id'); }
}
