<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class PublicHomeHeroAuroraTest extends TestCase
{
    private function css(): string
    {
        $css = file_get_contents(dirname(__DIR__, 2).'/public/css/home-hero-aurora.css');
        self::assertIsString($css);

        return $css;
    }

    public function test_light_and_dark_background_have_distinct_low_noise_palette(): void
    {
        $css = $this->css();

        self::assertStringContainsString(':root:not([data-theme="dark"]) .site-body .marketplace-hero-scene', $css);
        self::assertStringContainsString(':root[data-theme="dark"] .site-body .marketplace-hero-scene', $css);
        foreach (['#f6f8fc', '#07162b', 'rgba(165, 230, 223, .30)', 'rgba(12, 177, 160, .24)'] as $palette) {
            self::assertStringContainsString($palette, $css);
        }
        self::assertStringContainsString('--home-hero-seam:', $css);
        self::assertStringContainsString('box-shadow: inset 0 -1px 0', $css);
    }

    public function test_glows_and_grid_are_purely_decorative_static_css(): void
    {
        $css = $this->css();

        self::assertStringContainsString('.site-body .marketplace-hero-scene::before', $css);
        self::assertStringContainsString('.site-body .marketplace-hero-scene::after', $css);
        self::assertStringContainsString('radial-gradient(', $css);
        self::assertStringContainsString('48px 48px', $css);
        self::assertStringContainsString('40px 40px', $css);
        self::assertStringContainsString('pointer-events', file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css'));
        self::assertStringContainsString('animation: none', $css);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
        self::assertDoesNotMatchRegularExpression('/@keyframes|@import|url\s*\(|animation\s*:(?!\s*none)/i', $css);
    }

    public function test_homepage_markup_and_search_remain_present(): void
    {
        $layout = file_get_contents(dirname(__DIR__, 2).'/resources/views/layouts/main.blade.php');
        $home = file_get_contents(dirname(__DIR__, 2).'/resources/views/home.blade.php');
        self::assertIsString($layout);
        self::assertIsString($home);

        self::assertSame(1, substr_count($layout, "{{ asset('css/home-hero-aurora.css') }}?v=1"));
        self::assertSame(1, substr_count($home, '<div class="marketplace-hero-scene">'));
        self::assertSame(1, substr_count($home, 'class="hero container marketplace-home-hero"'));
        self::assertStringContainsString('class="hero-search"', $home);
        self::assertStringContainsString('class="hero-links"', $home);
        self::assertStringContainsString('class="hero-quick-actions"', $home);
        self::assertStringContainsString('class="container marketplace-type-directory"', $home);
        self::assertStringContainsString("{{ asset('css/footer-two-tone-themes.css') }}?v=2", $layout);
    }
}
