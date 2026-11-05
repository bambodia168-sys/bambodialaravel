<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractMessage extends Model
{
    public $timestamps=false;
    protected $fillable=['freelance_contract_id','sender_id','body','created_at'];
    protected function casts(): array { return ['created_at'=>'datetime']; }
    public function author(): BelongsTo { return $this->belongsTo(User::class,'sender_id'); }
}
