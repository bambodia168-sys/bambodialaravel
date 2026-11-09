<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreatorBadge extends Model
{
    protected $fillable = ['slug','name','description','icon','tone','grant_mode','enabled','sort_order','auto_rules','eligibility_criteria'];
    protected function casts(): array { return ['enabled'=>'boolean','auto_rules'=>'array']; }
    public function grants(): HasMany { return $this->hasMany(CreatorBadgeGrant::class); }
}
