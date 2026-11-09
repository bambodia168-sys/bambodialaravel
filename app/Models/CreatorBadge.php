<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreatorBadge extends Model
{
    protected $fillable = ['name','description','icon','tone','grant_mode','enabled','sort_order'];
    protected function casts(): array { return ['enabled'=>'boolean']; }
    public function grants(): HasMany { return $this->hasMany(CreatorBadgeGrant::class); }
}
