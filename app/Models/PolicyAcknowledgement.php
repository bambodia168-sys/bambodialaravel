<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyAcknowledgement extends Model
{
    public $timestamps=false;
    protected $fillable=['policy_publication_id','user_id','sha256','record_type','created_at'];
    protected function casts(): array { return ['created_at'=>'datetime']; }
    public function publication(): BelongsTo { return $this->belongsTo(PolicyPublication::class,'policy_publication_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
