<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class FooterMarketplaceShortLabelsTest extends TestCase
{
    private function source(string $relative): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/'.$relative);
        self::assertIsString($contents);
        return $contents;
    }

    public function test_public_footer_keeps_five_existing_destinations_with_short_labels(): void
    {
        $layout = $this->source('resources/views/layouts/main.blade.php');
        $footer = explode('</footer>', explode('<footer class="site-footer">', $layout, 2)[1] ?? '', 2)[0];
        self::assertNotSame('', $footer);

        foreach ([
            "url('/search?type=project') }}\">{{ __('ui.footer_marketplace_projects') }}</a>",
            "url('/search?type=product') }}\">{{ __('ui.footer_marketplace_products') }}</a>",
            "url('/search?type=freelance') }}\">{{ __('ui.footer_marketplace_freelance') }}</a>",
            "url('/search?type=service') }}\">{{ __('ui.footer_marketplace_services') }}</a>",
            "route('jobs') }}\">{{ __('ui.footer_marketplace_jobs') }}</a>",
        ] as $expected) {
            self::assertStringContainsString($expected, $footer);
        }

        self::assertStringContainsString("FeatureFlag::isEnabled('marketplace_search')", $footer);
        self::assertStringContainsString("Route::has('jobs')", $footer);
        self::assertStringNotContainsString("__('ui.find_projects')", $footer);
        self::assertStringNotContainsString("__('ui.find_products')", $footer);
        self::assertStringNotContainsString("__('ui.find_freelance_offers')", $footer);
        self::assertStringNotContainsString("__('ui.find_services')", $footer);
        self::assertStringNotContainsString('>Find Jobs</a>', $footer);
    }

    public function test_english_footer_labels_are_exactly_the_five_categories(): void
    {
        $language = $this->source('lang/en/ui.php');
        foreach ([
            'projects' => 'Projects',
            'products' => 'Products',
            'freelance' => 'Freelance',
            'services' => 'Services',
            'jobs' => 'Jobs',
        ] as $key => $label) {
            self::assertStringContainsString(
                "'footer_marketplace_".$key."' => '".$label."'",
                $language
            );
        }
    }

    public function test_short_footer_labels_are_translated_without_removing_descriptive_keys(): void
    {
        $keys = [
            'footer_marketplace_projects',
            'footer_marketplace_products',
            'footer_marketplace_freelance',
            'footer_marketplace_services',
            'footer_marketplace_jobs',
        ];
        foreach (['en','de','ja','km','ko','th','vi','zh'] as $locale) {
            $language = $this->source('lang/'.$locale.'/ui.php');
            foreach ($keys as $key) {
                self::assertSame(1, substr_count($language, "'".$key."' =>"), $locale.' '.$key);
            }
            self::assertStringContainsString("'find_freelance_offers' =>", $language);
            self::assertStringContainsString("'find_freelancers' =>", $language);
        }
    }

    public function test_user_legal_hero_and_footer_design_stay_untouched(): void
    {
        $layout = $this->source('resources/views/layouts/main.blade.php');

        self::assertStringContainsString("<h3>{{ __('ui.for_users') }}</h3>", $layout);
        self::assertStringContainsString("<h3>{{ __('ui.footer_legal_policies') }}</h3>", $layout);
        self::assertStringContainsString("route('policy-center')", $layout);
        self::assertStringContainsString("route('support')", $layout);
        self::assertStringContainsString("footer-bottom-band", $layout);
        self::assertStringContainsString("css/footer-two-tone-themes.css", $layout);
        self::assertStringContainsString("css/home-hero-aurora.css", $layout);
    }
}
