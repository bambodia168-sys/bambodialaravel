<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Socialite\Two\FacebookProvider;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\LinkedInOpenIdProvider;

final class SocialLoginProvider extends Model
{
    public const PROVIDERS = [
        'google' => ['Google', GoogleProvider::class],
        'facebook' => ['Facebook', FacebookProvider::class],
        'linkedin' => ['LinkedIn', LinkedInOpenIdProvider::class],
        'github' => ['GitHub', GithubProvider::class],
    ];

    protected $primaryKey = 'provider';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['provider', 'client_id', 'client_secret', 'enabled'];
    protected $hidden = ['client_secret'];

    protected function casts(): array
    {
        return ['client_secret' => 'encrypted', 'enabled' => 'boolean'];
    }

    public static function enabledProviders(): array
    {
        return self::query()->where('enabled', true)->whereIn('provider', array_keys(self::PROVIDERS))
            ->pluck('provider')->all();
    }

    public static function callbackUrl(string $provider): string
    {
        return rtrim(config('app.url'), '/').'/auth/'.$provider.'/callback';
    }
}
