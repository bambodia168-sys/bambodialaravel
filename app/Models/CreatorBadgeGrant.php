<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreatorBadgeGrant extends Model
{
    protected $fillable = ['creator_badge_id','user_id','granted_by','source','evidence_note'];
    public function badge(): BelongsTo { return $this->belongsTo(CreatorBadge::class,'creator_badge_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
