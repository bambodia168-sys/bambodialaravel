<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetailSupportResource extends Model
{
    public const KINDS = ['documentation', 'guide', 'faq', 'video', 'other'];

    protected $fillable = [
        'subject_type', 'subject_id', 'author_id', 'kind',
        'title', 'description', 'url', 'is_enabled', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_enabled'=>'boolean', 'sort_order'=>'integer'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
