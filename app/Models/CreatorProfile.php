<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreatorProfile extends Model
{
    protected $fillable=['user_id','headline','biography','website_url','is_public'];

    protected function casts(): array { return ['is_public'=>'boolean']; }

    public function owner(): BelongsTo { return $this->belongsTo(User::class,'user_id'); }
}
