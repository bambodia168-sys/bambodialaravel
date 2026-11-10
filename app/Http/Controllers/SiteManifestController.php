<?php

namespace App\Http\Controllers;

use App\Models\SiteBrandAsset;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;

final class SiteManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'name' => SiteSetting::setting('identity.name'),
            'short_name' => SiteSetting::setting('identity.name'),
            'description' => SiteSetting::setting('identity.tagline'),
            'start_url' => '/',
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
            ->header('Cache-Control', 'public, max-age=300');
    }
}
