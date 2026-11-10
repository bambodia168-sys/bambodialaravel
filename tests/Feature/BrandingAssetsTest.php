<?php

namespace Tests\Feature;

use App\Models\SiteBrandAsset;
use Tests\TestCase;

class BrandingAssetsTest extends TestCase
{
    public function test_default_assets_and_dynamic_header_brand_bindings_are_present(): void
    {
        $originalAssets=[
            'favicon.ico','favicon.png','apple-touch-icon.png',
            'images/bambodia-logo.webp','images/bambodia-logo-dark.svg',
        ];
        foreach ($originalAssets as $asset) {
            $this->assertFileExists(public_path($asset));
        }

        // Retain the exact default logo artwork while allowing admin uploads
        // to supersede it. This is a source-level test, no database required.
        $this->assertSame('images/bambodia-logo.webp', SiteBrandAsset::SLOTS['logo_light']['fallback']);
        $this->assertSame('images/bambodia-logo-dark.svg', SiteBrandAsset::SLOTS['logo_dark']['fallback']);
        $main=file_get_contents(resource_path('views/layouts/main.blade.php'));
        $workspace=file_get_contents(resource_path('views/layouts/workspace.blade.php'));
        foreach ([$main,$workspace] as $html) {
            $this->assertStringContainsString("SiteBrandAsset::urlFor('logo_light')", $html);
            $this->assertStringContainsString("SiteBrandAsset::urlFor('logo_dark')", $html);
            $this->assertStringContainsString("SiteSetting::setting('identity.name')", $html);
        }

        $brandHead=file_get_contents(resource_path('views/layouts/partials/site-brand-head.blade.php'));
        $this->assertStringContainsString("SiteBrandAsset::urlFor('favicon_light')", $brandHead);
        $this->assertStringContainsString("SiteBrandAsset::urlFor('favicon_dark')", $brandHead);
        $this->assertStringContainsString("SiteBrandAsset::urlFor('touch_icon')", $brandHead);
        foreach (['main','auth','workspace'] as $layout) {
            $html=file_get_contents(resource_path("views/layouts/{$layout}.blade.php"));
            $this->assertStringContainsString("@include('layouts.partials.site-brand-head')", $html);
        }
    }
}
