<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class FooterLegalAndUsersTest extends TestCase
{
    private function source(string $path): string
    {
        $data = file_get_contents(dirname(__DIR__, 2).'/'.$path);
        self::assertIsString($data);
        return $data;
    }

    public function test_policy_center_moves_to_legal_column_without_changing_route(): void
    {
        $layout = $this->source('resources/views/layouts/main.blade.php');
        $footer = explode('</footer>', explode('<footer class="site-footer">', $layout, 2)[1] ?? '', 2)[0];
        self::assertNotSame('', $footer);
        self::assertSame(1, substr_count($footer, "route('policy-center')"));

        $usersStart = strpos($footer, "<h3>{{ __('ui.for_users') }}</h3>");
        $legalStart = strpos($footer, "<h3>{{ __('ui.footer_legal_policies') }}</h3>");
        self::assertIsInt($usersStart);
        self::assertIsInt($legalStart);
        self::assertGreaterThan($usersStart, $legalStart);

        $users = substr($footer, $usersStart, $legalStart-$usersStart);
        $legal = substr($footer, $legalStart);
        self::assertStringNotContainsString("route('policy-center')", $users);
        self::assertStringContainsString(
            "@if(Route::has('policy-center'))<a href=\"{{ route('policy-center') }}\">Policy Center</a>@endif",
            $legal
        );
        self::assertStringContainsString("route('support')", $users);
        self::assertStringContainsString("route('register')", $users);
    }

    public function test_email_support_is_consistently_capitalized_and_conditional(): void
    {
        $layout = $this->source('resources/views/layouts/main.blade.php');
        self::assertStringContainsString("SiteSetting::setting('site.support_email')!==''", $layout);
        self::assertStringContainsString("mailto:{{ \\App\\Models\\SiteSetting::setting('site.support_email') }}", $layout);
        self::assertStringContainsString("__('ui.footer_email_support')", $layout);
        self::assertStringNotContainsString('>Email support</a>', $layout);
    }

    public function test_footer_copy_has_translations_and_preserves_old_compliance_key(): void
    {
        foreach (['en','de','ja','km','ko','th','vi','zh'] as $locale) {
            $content = $this->source('lang/'.$locale.'/ui.php');
            self::assertSame(1, substr_count($content, "'footer_legal_policies' =>"), $locale);
            self::assertSame(1, substr_count($content, "'footer_email_support' =>"), $locale);
            self::assertStringContainsString("'compliance' =>", $content);
        }
        $en = $this->source('lang/en/ui.php');
        self::assertStringContainsString("'footer_legal_policies' => 'Legal & Policies'", $en);
        self::assertStringContainsString("'footer_email_support' => 'Email Support'", $en);
    }

    public function test_approved_aurora_and_marketplace_layout_still_load(): void
    {
        $layout = $this->source('resources/views/layouts/main.blade.php');
        self::assertStringContainsString("css/home-hero-aurora.css", $layout);
        self::assertStringContainsString("css/footer-two-tone-themes.css", $layout);
        self::assertStringContainsString("ui.footer_marketplace_projects", $layout);
        self::assertStringContainsString("ui.footer_marketplace_freelance", $layout);
        self::assertStringContainsString("footer-bottom-band", $layout);
    }
}
