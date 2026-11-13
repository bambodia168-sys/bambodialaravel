<?php

namespace Tests\Feature;

use Tests\TestCase;

final class HeaderFlagScrollTopTest extends TestCase
{
    public function test_global_public_and_account_headers_show_selected_language_flag_only(): void
    {
        $public = file_get_contents(resource_path('views/layouts/main.blade.php'));
        $workspace = file_get_contents(resource_path('views/layouts/workspace.blade.php'));
        $menu = file_get_contents(resource_path('views/components/language-menu.blade.php'));
        $css = file_get_contents(public_path('css/header-flag-scroll-top.css'));

        $this->assertStringContainsString(
            '<x-language-menu class="nav-language-menu header-flag-only"/>',
            $public
        );
        $this->assertStringContainsString(
            '<x-language-menu class="header-flag-only"/>',
            $workspace
        );

        // All workspace layouts (administrator, account, creator and developer)
        // use the same shared account header.
        $this->assertStringContainsString(
            "['admin'=>'Administration','account'=>'My Account','creator'=>'Creator Studio','developer'=>'Developer Workspace']",
            $workspace
        );

        // Dropdown markup and the existing accessible POST locale-switching
        // behavior must remain unchanged. Only the trigger is visually compact.
        $this->assertStringContainsString('<x-language-flag :code="$current"/>', $menu);
        $this->assertStringContainsString("route('locale.update')", $menu);
        $this->assertStringContainsString('class="language-option', $menu);
        $this->assertStringContainsString('aria-label="{{ __(\'ui.language\') }}: {{ $names[$current] ?? \'English\' }}"', $menu);
        $this->assertStringContainsString('header-flag-only > .language-trigger .language-current', $css);
        $this->assertStringContainsString('header-flag-only > .language-trigger > .locale-flag', $css);

        foreach ([$public, $workspace] as $layout) {
            $this->assertStringContainsString(
                "asset('css/header-flag-scroll-top.css')", $layout
            );
        }
    }

    public function test_back_to_top_uses_svg_only_and_respects_reduced_motion(): void
    {
        $public = file_get_contents(resource_path('views/layouts/main.blade.php'));
        $css = file_get_contents(public_path('css/header-flag-scroll-top.css'));
        $js = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString(
            'data-back-to-top aria-label="Back to top" title="Back to top"',
            $public
        );
        $this->assertStringContainsString('<x-icon name="arrow-up" size="22"/>', $public);
        $this->assertStringNotContainsString('back-to-top-label', $public);

        $this->assertStringContainsString('.back-to-top.is-visible:hover', $css);
        $this->assertStringContainsString('@keyframes bambodia-arrow-lift', $css);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
        $this->assertStringContainsString('window.scrollTo({ top: 0', $js);
        $this->assertStringContainsString("button.classList.toggle('is-visible', visible)", $js);
        $this->assertStringContainsString("button.tabIndex = visible ? 0 : -1", $js);
    }
}
