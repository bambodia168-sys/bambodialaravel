<?php

namespace Tests\Feature;

use Tests\TestCase;

class BrandingAssetsTest extends TestCase
{
    public function test_brand_assets_and_header_references_are_present(): void
    {
        foreach (['favicon.ico', 'favicon.png', 'apple-touch-icon.png', 'images/bambodia-logo.webp'] as $asset) {
            $this->assertFileExists(public_path($asset));
        }

        $main = file_get_contents(resource_path('views/layouts/main.blade.php'));
        $this->assertStringContainsString("asset('images/bambodia-logo.webp')", $main);
        $this->assertStringContainsString('alt="Bambodia"', $main);

        foreach (['main', 'auth', 'workspace'] as $layout) {
            $blade = file_get_contents(resource_path("views/layouts/{$layout}.blade.php"));
            $this->assertStringContainsString("asset('favicon.png')", $blade);
            $this->assertStringContainsString("asset('favicon.ico')", $blade);
            $this->assertStringContainsString("asset('apple-touch-icon.png')", $blade);
        }
    }
}
