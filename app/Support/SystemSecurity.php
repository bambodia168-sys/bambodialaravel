<?php

namespace App\Support;

use App\Models\SiteSetting;

final class SystemSecurity
{
    public const CONTROLS = [
        'passkeys' => ['Passkeys', 'Allow accounts to register a passkey and sign in with it.', false],
        'two_factor_enrollment' => ['Two-factor authentication (2FA) enrollment', 'Allow new authenticator setup. Existing authenticator challenges and recovery codes continue to work.', true],
        'right_click' => ['Right-click protection', 'Block the context menu within protected public content.', false],
        'content_protector' => ['Content protector', 'Block ordinary copy and drag actions within protected public content.', false],
        'selection_protector' => ['Selection protector', 'Disable text selection within protected public content.', false],
    ];

    public static function states(array $keys): array
    {
        $saved = SiteSetting::query()->whereIn('key', array_map(self::key(...), $keys))->pluck('value', 'key');

        return array_combine($keys, array_map(
            fn (string $key) => ($saved[self::key($key)] ?? (self::CONTROLS[$key][2] ? '1' : '0')) === '1',
            $keys,
        ));
    }

    public static function enabled(string $control): bool
    {
        return self::states([$control])[$control];
    }

    public static function key(string $control): string
    {
        return 'security.'.$control;
    }
}
