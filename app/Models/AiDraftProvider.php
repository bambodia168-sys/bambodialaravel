<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AiDraftProvider extends Model
{
    public const MODELS = [
        'gpt-4.1-mini' => 'GPT-4.1 mini',
        'gpt-4o-mini' => 'GPT-4o mini',
    ];

    protected $fillable = [
        'provider', 'model', 'api_key', 'daily_limit',
        'global_daily_limit', 'is_enabled', 'verified_at', 'updated_by',
    ];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_enabled' => 'boolean',
            'verified_at' => 'datetime',
            'daily_limit' => 'integer',
            'global_daily_limit' => 'integer',
        ];
    }

    public static function configured(): ?self
    {
        return static::query()->find(1);
    }

    public function ready(): bool
    {
        return $this->provider === 'openai'
            && array_key_exists($this->model, self::MODELS)
            && $this->is_enabled
            && $this->verified_at !== null
            && is_string($this->api_key)
            && strlen($this->api_key) >= 20;
    }
}
