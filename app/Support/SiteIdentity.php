<?php

namespace App\Support;

use App\Models\MediaAsset;
use App\Models\SiteBrandAsset;
use App\Models\SiteLanguage;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Storage;

final class SiteIdentity
{
    public const FIELDS = [
        'tagline' => 'identity.tagline',
        'title' => 'seo.default_title',
        'description' => 'seo.default_description',
    ];

    public const SHARED_IMAGE = 'social_shared';

    public static function metadata(string $locale): array
    {
        $keys = array_map(fn (string $field) => self::settingKey($locale, $field), array_keys(self::FIELDS));
        $values = SiteSetting::query()->whereIn('key', $keys)->pluck('value', 'key');
        $defaults = require lang_path('site-identity.php');
        $result = [];
        foreach (self::FIELDS as $field => $legacyKey) {
            $result[$field] = trim((string) $values->get(self::settingKey($locale, $field)))
                ?: ($defaults[$locale][$field] ?? SiteSetting::setting($legacyKey));
        }

        return $result;
    }

    public static function settingKey(string $locale, string $field): string
    {
        return 'identity.localized.'.$locale.'.'.$field;
    }

    public static function imageSlot(string $locale): string
    {
        return 'social_'.$locale;
    }

    public static function sharingSlots(): array
    {
        return [self::SHARED_IMAGE => 'Shared fallback image'] + collect(SiteLanguage::CATALOG)
            ->mapWithKeys(fn (string $name, string $code) => [self::imageSlot($code) => $name.' sharing image'])
            ->all();
    }

    public static function sharingImage(string $locale): string
    {
        $slot = self::imageSlot($locale);
        $images = SiteBrandAsset::query()->whereIn('slot', [$slot, self::SHARED_IMAGE])->get()->keyBy('slot');

        return self::imageUrl($images->get($slot)) ?: self::sharedImage($images->get(self::SHARED_IMAGE));
    }

    public static function sharedImage(?SiteBrandAsset $image): string
    {
        return self::imageUrl($image) ?: (SiteSetting::setting('seo.default_og_image')
            ?: asset('images/bambodia-social-sharing.jpg'));
    }

    public static function imageUrl(?SiteBrandAsset $image): ?string
    {
        return $image && Storage::disk('public')->exists($image->storage_path)
            ? Storage::disk('public')->url($image->storage_path)
            : null;
    }

    public static function featuredImage(?MediaAsset $image): string
    {
        return $image && in_array($image->mime_type, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)
            ? $image->previewUrl()
            : '';
    }
}
