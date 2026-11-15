<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MarketplaceCategory extends Model
{
    public const TYPES = ['product', 'project', 'freelance', 'service', 'job'];

    protected $fillable = ['type','name','description','is_active','sort_order','created_by','parent_id'];

    protected function casts(): array
    {
        return ['is_active'=>'boolean','sort_order'=>'integer','parent_id'=>'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function(self $category): void {
            for($attempt=0;$attempt<30;$attempt++){
                $id=(string)random_int(100000000,999999999);
                if(!self::query()->where('public_id',$id)->exists()){
                    $category->public_id=$id;
                    $category->slug=Str::slug($category->name) ?: 'category-'.$id;
                    return;
                }
            }
            throw new \RuntimeException('Unable to allocate category identifier.');
        });
        static::updating(function(self $category): void {
            if($category->isDirty('name')) {
                $category->slug=Str::slug($category->name) ?: 'category-'.$category->public_id;
            }
        });
    }

    public function parent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(MarketplaceJob::class, 'category', 'slug');
    }

    public function listings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class,'category_id');
    }
}
