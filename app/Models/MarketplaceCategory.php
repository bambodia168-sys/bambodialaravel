<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MarketplaceCategory extends Model
{
    protected $fillable = ['type','name','description','is_active','sort_order','created_by'];

    protected function casts(): array
    {
        return ['is_active'=>'boolean','sort_order'=>'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function(self $category): void {
            $category->slug=Str::slug($category->name);
            for($attempt=0;$attempt<30;$attempt++){
                $id=(string)random_int(100000000,999999999);
                if(!self::query()->where('public_id',$id)->exists()){
                    $category->public_id=$id;
                    return;
                }
            }
            throw new \RuntimeException('Unable to allocate category identifier.');
        });
        static::updating(function(self $category): void {
            if($category->isDirty('name')) {
                $category->slug=Str::slug($category->name);
            }
        });
    }

    public function listings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class,'category_id');
    }
}
