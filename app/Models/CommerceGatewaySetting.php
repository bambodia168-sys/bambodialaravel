<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CommerceGatewaySetting extends Model
{
    public const FIELDS = [
        'stripe' => ['secret' => 'API secret key', 'webhook_secret' => 'Webhook signing secret'],
        'paypal' => ['client_id' => 'Client ID', 'secret' => 'Client secret', 'merchant_id' => 'Merchant ID', 'webhook_id' => 'Webhook ID'],
        'aba' => ['merchant_id' => 'PayWay merchant ID', 'secret' => 'PayWay API key'],
        'bakong' => ['account_id' => 'Bakong account ID', 'merchant_name' => 'Recipient name', 'merchant_city' => 'Recipient city', 'token' => 'Bakong API access token'],
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'live_mode' => 'boolean'];
    }

    public static function forMode(string $provider, bool $live): Builder
    {
        return static::query()->where('provider', $provider)->where('live_mode', $live);
    }

    public static function effective(string $provider, bool $live): array
    {
        $stored = static::forMode($provider, $live)->first()?->credentials;
        $fallback = (bool) config('commerce.live_gateways') === $live ? config("commerce.{$provider}", []) : [];

        return array_replace($fallback, $stored ?? []);
    }

    public static function configured(string $provider, bool $live, array $credentials): bool
    {
        foreach (array_keys(self::FIELDS[$provider] ?? []) as $field) {
            if (! filled($credentials[$field] ?? null)) {
                return false;
            }
        }

        return match ($provider) {
            'stripe' => str_starts_with($credentials['secret'], $live ? 'sk_live_' : 'sk_test_'),
            'paypal', 'aba', 'bakong' => true,
            default => false,
        };
    }
}
