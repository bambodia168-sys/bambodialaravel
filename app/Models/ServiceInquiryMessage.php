<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceInquiryMessage extends Model
{
    protected $fillable = ['service_inquiry_id','sender_id','body'];
    public function inquiry(): BelongsTo { return $this->belongsTo(ServiceInquiry::class,'service_inquiry_id'); }
    public function sender(): BelongsTo { return $this->belongsTo(User::class,'sender_id'); }
}
