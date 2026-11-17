<?php

namespace App\Http\Controllers;

use App\Models\SiteBrandAsset;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;
use App\Support\SiteIdentity;
use Illuminate\Http\Request;

final class SiteManifestController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $locale = app()->getLocale();
        $explicitLocale = $locale === $request->segment(1);

        return response()->json([
            'name' => SiteSetting::setting('identity.name'),
            'short_name' => SiteSetting::setting('identity.name'),
            'description' => SiteIdentity::metadata($locale)['tagline'],
            'lang' => $locale,
            'start_url' => $explicitLocale ? '/'.$locale : '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => '#c2410c',
            'icons' => [[
                'src' => SiteBrandAsset::urlFor('pwa_icon'),
                'sizes' => 'any',
                'type' => 'image/png',
                'purpose' => 'any',
            ]],
        ])->header('Content-Type', 'application/manifest+json; charset=utf-8')
            ->header('Cache-Control', $explicitLocale ? 'public, max-age=300' : 'private, max-age=300');
    }
}
