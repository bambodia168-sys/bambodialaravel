<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilestoneReview extends Model
{
    public $timestamps=false;
    protected $fillable=['milestone_submission_id','reviewed_by','decision','feedback','created_at'];
    protected function casts(): array { return ['created_at'=>'datetime']; }
    public function submission(): BelongsTo { return $this->belongsTo(MilestoneSubmission::class,'milestone_submission_id'); }
}
