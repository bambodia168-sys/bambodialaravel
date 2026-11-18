<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SocialLoginIdentity extends Model
{
    protected $fillable = ['user_id', 'provider', 'subject_hash'];
    protected $hidden = ['subject_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
