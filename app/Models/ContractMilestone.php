<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractMilestone extends Model
{
    protected $fillable = [
        'freelance_contract_id','sequence','title','description','amount','due_on','status','accepted_at',
    ];
    protected function casts(): array
    {
        return ['amount'=>'decimal:2','due_on'=>'date','accepted_at'=>'datetime'];
    }
    public function contract(): BelongsTo { return $this->belongsTo(FreelanceContract::class,'freelance_contract_id'); }
    public function submissions(): HasMany { return $this->hasMany(MilestoneSubmission::class); }
}
