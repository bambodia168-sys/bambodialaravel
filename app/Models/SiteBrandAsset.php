<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

final class SiteBrandAsset extends Model
{
    protected $primaryKey = 'slot';
    protected $keyType = 'string';
    public $incrementing = false;

    /**
     * Shared, user-visible upload guidance and server-side constraints.
     * The logos are deliberately flexible in aspect ratio; icons need exact
     * dimensions so browser tabs, touch shortcuts and PWA installs stay sharp.
     */
    public const SLOTS = [
        'logo_light' => [
            'label' => 'Header & footer logo (light)',
            'fallback' => 'images/bambodia-logo.webp',
            'size' => '1200 × 300 px',
            'width' => 1200, 'height' => 300, 'exact' => false,
            'file_type' => 'PNG, WebP or JPG',
            'mimes' => 'png,webp,jpeg,jpg',
            'accept' => 'image/png,image/webp,image/jpeg',
            'hint' => 'Recommended 4:1 horizontal artwork. Transparent PNG or WebP works best.',
        ],
        'logo_dark' => [
            'label' => 'Header & footer logo (dark)',
            'fallback' => 'images/bambodia-logo-dark.svg',
            'size' => '1200 × 300 px',
            'width' => 1200, 'height' => 300, 'exact' => false,
            'file_type' => 'PNG, WebP or JPG',
            'mimes' => 'png,webp,jpeg,jpg',
            'accept' => 'image/png,image/webp,image/jpeg',
            'hint' => 'Recommended 4:1 horizontal artwork in light colors for dark backgrounds.',
        ],
        'favicon_light' => [
            'label' => 'Browser favicon (light)',
            'fallback' => 'favicon.png',
            'size' => '64 × 64 px',
            'width' => 64, 'height' => 64, 'exact' => true,
            'file_type' => 'Transparent PNG',
            'mimes' => 'png',
            'accept' => 'image/png',
            'hint' => 'Exact square size. Transparent corners prevent white edges in browser tabs.',
        ],
        'favicon_dark' => [
            'label' => 'Browser favicon (dark)',
            'fallback' => 'favicon.png',
            'size' => '64 × 64 px',
            'width' => 64, 'height' => 64, 'exact' => true,
            'file_type' => 'Transparent PNG',
            'mimes' => 'png',
            'accept' => 'image/png',
            'hint' => 'Exact square size. Keep corners transparent for dark browser tab themes.',
        ],
        'touch_icon' => [
            'label' => 'Apple touch icon',
            'fallback' => 'apple-touch-icon.png',
            'size' => '180 × 180 px',
            'width' => 180, 'height' => 180, 'exact' => true,
            'file_type' => 'PNG',
            'mimes' => 'png',
            'accept' => 'image/png',
            'hint' => 'Exact square size. Use a full-bleed icon; iOS applies its own corner mask.',
        ],
        'pwa_icon' => [
            'label' => 'Mobile/PWA icon',
            'fallback' => 'favicon.png',
            'size' => '512 × 512 px',
            'width' => 512, 'height' => 512, 'exact' => true,
            'file_type' => 'PNG',
            'mimes' => 'png',
            'accept' => 'image/png',
            'hint' => 'Exact square size. Keep important artwork within the central 80% for cropping.',
        ],
    ];

    protected $fillable = ['slot', 'storage_path', 'mime_type', 'bytes', 'updated_by'];

    public static function urlFor(string $slot): string
    {
        if (! array_key_exists($slot, self::SLOTS)) {
            throw new \InvalidArgumentException('Unknown branding asset slot.');
        }

        $file = static::query()->whereKey($slot)->first();
        if ($file) {
            return Storage::disk('public')->url($file->storage_path);
        }

        // The original artwork remains untouched; the optional alpha-cleaned
        // default may be installed separately after the favicon has been reviewed.
        if (in_array($slot, ['favicon_light', 'favicon_dark'], true)
            && is_file(public_path('favicon-clean.png'))) {
            return asset('favicon-clean.png');
        }

        return asset(self::SLOTS[$slot]['fallback']);
    }

    public static function actualMimeFor(string $slot): string
    {
        if (! array_key_exists($slot, self::SLOTS)) {
            throw new \InvalidArgumentException('Unknown branding asset slot.');
        }

        $file = static::query()->whereKey($slot)->first();
        if ($file) {
            return $file->mime_type;
        }

        return str_ends_with(self::SLOTS[$slot]['fallback'], '.svg')
            ? 'image/svg+xml'
            : (str_ends_with(self::SLOTS[$slot]['fallback'], '.webp')
                ? 'image/webp' : 'image/png');
    }

    public static function actualDimensionsFor(string $slot): ?array
    {
        if (! array_key_exists($slot, self::SLOTS)) {
            throw new \InvalidArgumentException('Unknown branding asset slot.');
        }

        $file = static::query()->whereKey($slot)->first();
        $path = $file
            ? Storage::disk('public')->path($file->storage_path)
            : public_path(self::SLOTS[$slot]['fallback']);

        if (! is_file($path)) {
            return null;
        }

        $size = @getimagesize($path);
        if (! is_array($size) || $size[0] < 1 || $size[1] < 1) {
            return null;
        }

        return [$size[0], $size[1]];
    }
}
