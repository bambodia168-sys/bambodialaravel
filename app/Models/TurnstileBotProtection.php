<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

final class TurnstileBotProtection extends Model
{
    public $incrementing = false;

    protected $fillable = [
        'id','site_key','secret_key','is_enabled','protect_login','protect_registration',
        'protect_password_reset','tested_at','updated_by',
    ];

    protected function casts(): array
    {
        return [
            'secret_key'=>'encrypted',
            'is_enabled'=>'boolean',
            'protect_login'=>'boolean',
            'protect_registration'=>'boolean',
            'protect_password_reset'=>'boolean',
            'tested_at'=>'datetime',
        ];
    }

    public static function installed(): bool
    {
        return Schema::hasTable('turnstile_bot_protections');
    }

    public static function current(): ?self
    {
        return self::installed() ? self::query()->find(1) : null;
    }

    public static function forAction(string $action): ?self
    {
        $config=self::current();
        if (! $config || ! $config->is_enabled) {
            return null;
        }
        $field=match ($action) {
            'login'=>'protect_login',
            'register'=>'protect_registration',
            'reset'=>'protect_password_reset',
            default=>null,
        };
        return $field && $config->{$field} ? $config : null;
    }
}
