<?php

namespace App\Models;

use App\Http\Middleware\SetLocale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class SiteSeoPage extends Model
{
    protected $fillable = [
        'path', 'locale', 'title', 'description', 'keywords', 'canonical_url',
        'og_image_url', 'is_indexable', 'is_active', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_indexable' => 'boolean', 'is_active' => 'boolean'];
    }

    public static function publicPath(Request $request): string
    {
        $path = '/'.ltrim($request->path(), '/');
        $segments = explode('/', trim($path, '/'));
        if (in_array($segments[0] ?? '', SetLocale::SUPPORTED, true)) {
            array_shift($segments);
            $path = '/'.implode('/', $segments);
        }
        return $path === '//' ? '/' : rtrim($path, '/') ?: '/';
    }

    public static function forRequest(Request $request): ?self
    {
        $path = self::publicPath($request);
        $locale = app()->getLocale();
        return self::query()->where('path', $path)->where('is_active', true)
            ->whereIn('locale', array_values(array_unique([$locale, 'en'])))
            ->orderByRaw('CASE WHEN locale = ? THEN 0 ELSE 1 END', [$locale])
            ->first();
    }
}
