<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobProposal extends Model
{
    protected $fillable = ['marketplace_job_id','freelancer_id','cover_message','proposed_budget','status','responded_at'];
    protected function casts(): array { return ['proposed_budget'=>'decimal:2', 'responded_at'=>'datetime']; }
    public function job(): BelongsTo { return $this->belongsTo(MarketplaceJob::class, 'marketplace_job_id'); }
    public function freelancer(): BelongsTo { return $this->belongsTo(User::class, 'freelancer_id'); }
}
