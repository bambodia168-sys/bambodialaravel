<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductLicenseEvent extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'product_license_id','actor_id','action','previous_domain','domain','reason','created_at',
    ];

    protected function casts(): array { return ['created_at'=>'datetime']; }
    public function license(): BelongsTo { return $this->belongsTo(ProductLicense::class, 'product_license_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class,'actor_id'); }
}
