<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BuilderTermsAcceptance extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'content_type', 'content_id', 'terms_sha256', 'accepted_at',
    ];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }
}
