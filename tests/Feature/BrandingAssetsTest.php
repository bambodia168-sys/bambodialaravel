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
    public function test_site_identity_shows_dimensions_and_supported_types_per_slot(): void
    {
        foreach ([
            'logo_light' => ['1200 × 300 px', 'PNG, WebP or JPG'],
            'logo_dark' => ['1200 × 300 px', 'PNG, WebP or JPG'],
            'favicon_light' => ['64 × 64 px', 'Transparent PNG'],
            'favicon_dark' => ['64 × 64 px', 'Transparent PNG'],
            'touch_icon' => ['180 × 180 px', 'PNG'],
            'pwa_icon' => ['512 × 512 px', 'PNG'],
        ] as $slot => [$size, $format]) {
            $this->assertSame($size, SiteBrandAsset::SLOTS[$slot]['size']);
            $this->assertSame($format, SiteBrandAsset::SLOTS[$slot]['file_type']);
        }

        $admin = \App\Models\User::query()->create([
            'name' => 'Brand Asset Test Admin',
            'email' => 'brand-specs-admin@example.test',
            'password' => 'BrandSpecsTestOnly123!',
            'role' => 'super_admin',
        ]);

        $this->actingAs($admin)->get(route('dashboard.site-identity'))
            ->assertOk()
            ->assertSee('Recommended size')
            ->assertSee('File type')
            ->assertSee('64 × 64 px')
            ->assertSee('180 × 180 px')
            ->assertSee('512 × 512 px')
            ->assertSee('Transparent PNG')
            ->assertSee('data-transparent-corners="1"', false)
            ->assertSee('js/site-identity-assets.js');
    }

    public function test_too_small_favicon_and_pwa_uploads_are_rejected(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $admin = \App\Models\User::query()->create([
            'name' => 'Incorrect Icon Admin',
            'email' => 'wrong-icons-admin@example.test',
            'password' => 'BrandSpecsTestOnly123!',
            'role' => 'super_admin',
        ]);

        // 1×1 PNG fixture: validation must reject wrong dimensions server-side
        // without GD or image generation dependencies.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl8evYAAAAASUVORK5CYII=',
            true
        );

        foreach (['favicon_light', 'favicon_dark', 'touch_icon', 'pwa_icon'] as $slot) {
            $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('wrong.png', $png);
            $this->actingAs($admin)->post(route('dashboard.site-identity.assets.store'), [
                'slot' => $slot, 'image' => $file,
            ])->assertSessionHasErrors('image');

            $this->assertDatabaseMissing('site_brand_assets', ['slot' => $slot]);
        }
    }

    public function test_pwa_manifest_declares_the_actual_default_png_dimensions(): void
    {
        $dimensions = getimagesize(public_path('favicon.png'));
        $this->assertIsArray($dimensions);

        $this->get('/site.webmanifest')
            ->assertOk()
            ->assertJsonPath('icons.0.type', 'image/png')
            ->assertJsonPath('icons.0.sizes', $dimensions[0].'x'.$dimensions[1]);
    }

}
