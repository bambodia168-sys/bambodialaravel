<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeatureFlag extends Model
{
    public const AVAILABLE = [
        'registration' => ['label' => 'Public account registration', 'description' => 'Controls access to new customer registration and account creation.'],
        'creator_submissions' => ['label' => 'Creator listing submission', 'description' => 'Controls new marketplace submissions from approved creator accounts.'],
        'marketplace_search' => ['label' => 'Marketplace search', 'description' => 'Controls public discovery of published products, projects, freelancers and services.'],
    ];

    protected $fillable = ['key', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public static function isEnabled(string $key): bool
    {
        if (! array_key_exists($key, self::AVAILABLE)) {
            return false;
        }

        return (bool) (self::query()->where('key', $key)->value('enabled') ?? true);
    }
}
