<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedJob extends Model
{
    protected $fillable=['user_id','marketplace_job_id'];

    public function job(): BelongsTo { return $this->belongsTo(MarketplaceJob::class,'marketplace_job_id'); }
    public function owner(): BelongsTo { return $this->belongsTo(User::class,'user_id'); }
}
