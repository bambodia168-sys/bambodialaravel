<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class PublicFooterAuroraThemeTest extends TestCase
{
    private function stylesheet(): string
    {
        $content = file_get_contents(dirname(__DIR__, 2).'/public/css/footer-two-tone-themes.css');
        self::assertIsString($content);

        return $content;
    }

    public function test_both_themes_retain_the_approved_two_tone_palette(): void
    {
        $css = $this->stylesheet();

        self::assertStringContainsString(':root:not([data-theme="dark"]) .site-body .site-footer', $css);
        self::assertStringContainsString(':root[data-theme="dark"] .site-body .site-footer', $css);

        foreach (['#f1f5fb', '#e6edf7', '#111d30', '#0b1424'] as $surface) {
            self::assertStringContainsString($surface, $css);
        }

        self::assertStringContainsString('--footer-heading-color:', $css);
        self::assertStringContainsString('--footer-link-color:', $css);
        self::assertStringContainsString('--footer-bottom-highlight:', $css);
        self::assertStringContainsString('.site-body .site-footer .footer-bottom-band', $css);
    }

    public function test_aurora_is_css_only_and_links_are_accessible(): void
    {
        $css = $this->stylesheet();

        self::assertStringContainsString('radial-gradient(', $css);
        self::assertStringContainsString('--footer-aurora-teal:', $css);
        self::assertStringContainsString('--footer-aurora-indigo:', $css);
        self::assertStringContainsString('--footer-grid-line:', $css);
        self::assertStringContainsString('48px 48px', $css);
        self::assertStringContainsString(':focus-visible', $css);
        self::assertStringContainsString('outline: 2px solid var(--footer-focus-ring)', $css);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
        self::assertStringContainsString('transition: none', $css);
        self::assertDoesNotMatchRegularExpression('/@keyframes|\banimation\s*:|url\s*\(/i', $css);
    }

    public function test_public_layout_uses_versioned_stylesheet_without_moving_footer(): void
    {
        $layout = file_get_contents(dirname(__DIR__, 2).'/resources/views/layouts/main.blade.php');
        self::assertIsString($layout);

        self::assertSame(1, substr_count($layout, "{{ asset('css/footer-two-tone-themes.css') }}?v=2"));
        self::assertStringNotContainsString("{{ asset('css/footer-two-tone-themes.css') }}?v=1", $layout);
        self::assertSame(1, substr_count($layout, '<footer class="site-footer">'));
        self::assertSame(1, substr_count($layout, '<div class="container footer-columns">'));
        self::assertSame(1, substr_count($layout, '<div class="footer-bottom-band">'));
        self::assertSame(1, substr_count($layout, 'data-back-to-top'));
    }
}
