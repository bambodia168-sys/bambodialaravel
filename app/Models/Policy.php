<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Policy extends Model
{
    protected $fillable = ['title', 'slug', 'body', 'category', 'jurisdiction', 'status', 'reviewed_at', 'reviewed_by'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $policy): void {
            $policy->slug = Str::slug($policy->title) ?: 'policy';
            for ($i = 0; $i < 30; $i++) {
                $candidate = (string) random_int(100000000, 999999999);
                if (! self::query()->where('public_id', $candidate)->exists()) {
                    $policy->public_id = $candidate;
                    return;
                }
            }
            throw new \RuntimeException('Unable to allocate a policy identifier.');
        });
        static::updating(function (self $policy): void {
            if ($policy->isDirty('title')) {
                $policy->slug = Str::slug($policy->title) ?: 'policy';
            }
        });
    }

    public function publicUrl(): string
    {
        return url('/policies').'?='.$this->public_id.'-'.$this->slug;
    }
}
