<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreelancerResume extends Model
{
    protected $fillable = ['user_id', 'headline', 'summary', 'experience', 'skills', 'portfolio_url', 'is_visible'];
    protected function casts(): array { return ['is_visible' => 'boolean']; }
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
}
