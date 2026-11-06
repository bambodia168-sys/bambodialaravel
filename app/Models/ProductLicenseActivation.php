<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductLicenseActivation extends Model
{
    protected $fillable = ['product_license_id','domain','status','activated_at','deactivated_at','last_validated_at'];

    protected function casts(): array
    {
        return ['activated_at'=>'datetime','deactivated_at'=>'datetime','last_validated_at'=>'datetime'];
    }

    public function license(): BelongsTo { return $this->belongsTo(ProductLicense::class, 'product_license_id'); }
}
