<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MilestoneSubmission extends Model
{
    public $timestamps=false;
    protected $fillable=['contract_milestone_id','submitted_by','delivery_notes','evidence_url','original_filename','storage_path','file_type','byte_size','sha256','created_at'];
    protected function casts(): array { return ['created_at'=>'datetime']; }
    public function milestone(): BelongsTo { return $this->belongsTo(ContractMilestone::class,'contract_milestone_id'); }
    public function author(): BelongsTo { return $this->belongsTo(User::class,'submitted_by'); }
    public function review(): HasOne { return $this->hasOne(MilestoneReview::class); }
}
