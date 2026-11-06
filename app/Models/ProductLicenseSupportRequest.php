<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductLicenseSupportRequest extends Model
{
    protected $fillable=[
        'product_license_id','user_id','term','status','reviewed_by',
        'reviewed_at','review_note','granted_from','granted_until',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at'=>'datetime',
            'granted_from'=>'datetime',
            'granted_until'=>'datetime',
        ];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(ProductLicense::class,'product_license_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class,'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class,'reviewed_by');
    }
}
