<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractConsent extends Model
{
    public $timestamps=false;
    protected $fillable=['freelance_contract_id','user_id','action','terms_sha256','created_at'];
    protected function casts(): array { return ['created_at'=>'datetime']; }
    public function actor(): BelongsTo { return $this->belongsTo(User::class,'user_id'); }
}
