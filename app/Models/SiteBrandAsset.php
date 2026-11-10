<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

final class SiteBrandAsset extends Model
{
    protected $primaryKey = 'slot';
    protected $keyType = 'string';
    public $incrementing = false;

    public const SLOTS = [
        'logo_light' => ['label' => 'Header & footer logo (light)', 'fallback' => 'images/bambodia-logo.webp'],
        'logo_dark' => ['label' => 'Header & footer logo (dark)', 'fallback' => 'images/bambodia-logo-dark.svg'],
        'favicon_light' => ['label' => 'Browser favicon (light)', 'fallback' => 'favicon.png'],
        'favicon_dark' => ['label' => 'Browser favicon (dark)', 'fallback' => 'favicon.png'],
        'touch_icon' => ['label' => 'Apple touch icon', 'fallback' => 'apple-touch-icon.png'],
        'pwa_icon' => ['label' => 'Mobile/PWA icon', 'fallback' => 'favicon.png'],
    ];

    protected $fillable = ['slot', 'storage_path', 'mime_type', 'bytes', 'updated_by'];

    public static function urlFor(string $slot): string
    {
        if (! array_key_exists($slot, self::SLOTS)) {
            throw new \InvalidArgumentException('Unknown branding asset slot.');
        }

        $file = static::query()->whereKey($slot)->first();
        return $file
            ? Storage::disk('public')->url($file->storage_path)
            : asset(self::SLOTS[$slot]['fallback']);
    }
}
