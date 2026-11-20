<?php

namespace App\Support;

use App\Models\SiteSetting;

final class VideoPlayerSettings
{
    public const KEY = 'video_player.settings';

    public const TYPES = ['bambo' => 'Bambo Player', 'plyr' => 'Plyr Player'];

    public const SKINS = ['classic' => 'Classic', 'midnight' => 'Midnight', 'ocean' => 'Ocean', 'contrast' => 'High contrast'];

    public const CONTROLS = [
        'bambo' => ['progress', 'time', 'volume', 'speed', 'pip', 'fullscreen'],
        'plyr' => ['progress', 'time', 'volume', 'speed', 'rewind', 'fast-forward', 'pip', 'fullscreen'],
    ];

    public const AD_PROVIDERS = ['custom' => 'Custom Ads', 'google' => 'Google Ads', 'facebook' => 'Facebook Ads'];

    public const AD_FORMATS = ['image' => 'Image', 'video' => 'Video', 'text' => 'Text', 'google_tag' => 'Google Ad Manager video tag'];

    public static function defaults(): array
    {
        return [
            'skin' => 'classic',
            'controls' => self::CONTROLS['bambo'],
            'logo_url' => null,
            'watermark_url' => null,
            'overlay_text' => null,
            'brand_position' => 'top-right',
            'ad' => [
                'enabled' => false,
                'provider' => 'custom',
                'format' => 'image',
                'media_url' => null,
                'text' => null,
                'link_url' => null,
                'duration' => 5,
            ],
        ];
    }

    public static function read(): array
    {
        return self::decode(SiteSetting::query()->whereKey(self::KEY)->value('value'));
    }

    public static function decode(?string $value): array
    {
        $saved = json_decode($value ?? '', true);
        $type = $saved['type'] ?? 'bambo';
        $settings = ['type' => is_string($type) && isset(self::TYPES[$type]) ? $type : 'bambo'];
        foreach (array_keys(self::TYPES) as $player) {
            $profile = $saved['profiles'][$player] ?? [];
            $profile = is_array($profile) ? $profile : [];
            $settings['profiles'][$player] = array_replace(self::defaults(), $profile);
            $settings['profiles'][$player]['controls'] = array_values(array_intersect(
                self::CONTROLS[$player], (array) ($profile['controls'] ?? self::defaults()['controls']),
            ));
            $settings['profiles'][$player]['ad'] = array_replace(self::defaults()['ad'], (array) ($profile['ad'] ?? []));
        }

        return $settings;
    }
}
