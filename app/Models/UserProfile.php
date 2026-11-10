<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserProfile extends Model
{
    protected $fillable = ['user_id','date_of_birth','nationality','bio','avatar_path','public_location','availability'];
    protected function casts(): array { return ['date_of_birth'=>'date']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}