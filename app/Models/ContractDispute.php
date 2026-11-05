<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractDispute extends Model
{
    protected $fillable=[
        'freelance_contract_id','opened_by','category','details','status','reviewed_by',
        'resolution_note','disposition','resolved_at',
    ];
    protected function casts(): array { return ['resolved_at'=>'datetime']; }
    public function contract(): BelongsTo { return $this->belongsTo(FreelanceContract::class,'freelance_contract_id'); }
    public function reporter(): BelongsTo { return $this->belongsTo(User::class,'opened_by'); }
}
