<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class FreelanceTerminologyTest extends TestCase
{
    private function source(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/'.$path);
        self::assertIsString($contents);
        return $contents;
    }

    public function test_listing_type_and_account_role_identifiers_are_preserved(): void
    {
        $types = $this->source('app/Models/MarketplaceListing.php');
        $users = $this->source('app/Models/User.php');
        $routes = $this->source('routes/web.php');

        self::assertStringContainsString("'freelance'", $types);
        self::assertStringContainsString("'freelancer'", $users);
        self::assertStringContainsString("'freelance', 'service'", $routes);
    }

    public function test_home_showcase_uses_people_word_and_directory_link(): void
    {
        $home = $this->source('resources/views/home.blade.php');

        self::assertStringContainsString('<h2 id="freelancer-section-title">Freelancers</h2>', $home);
        self::assertStringNotContainsString('<h2 id="freelancer-section-title">Freelancer</h2>', $home);
        self::assertStringContainsString("route('creators.index') }}\">Browse creator profiles</a>", $home);
        self::assertStringContainsString("'type'=>'freelance','title'=>'Freelance'", $home);
        self::assertStringContainsString("__('ui.freelance')", $home);
    }

    public function test_footer_freelance_offers_link_does_not_claim_to_find_people(): void
    {
        $layout = $this->source('resources/views/layouts/main.blade.php');
        self::assertStringContainsString(
            "url('/search?type=freelance') }}\">{{ __('ui.footer_marketplace_freelance') }}</a>",
            $layout
        );
        self::assertStringNotContainsString("__('ui.find_freelancers')", $layout);
    }

    public function test_translation_key_preserves_freelancer_term_and_adds_offers(): void
    {
        foreach (['en','zh','km','ko','ja','th','vi','de'] as $locale) {
            $source = $this->source('lang/'.$locale.'/ui.php');
            self::assertStringContainsString("'find_freelancers' =>", $source, $locale);
            self::assertStringContainsString("'find_freelance_offers' =>", $source, $locale);
            self::assertStringContainsString("'freelance' =>", $source, $locale);
        }
        self::assertStringContainsString(
            "'find_freelance_offers' => 'Find Freelance Offers'",
            $this->source('lang/en/ui.php')
        );
    }

    public function test_freelance_offer_search_and_individual_freelancer_cta_remain_distinct(): void
    {
        $search = $this->source('resources/views/marketplace/search.blade.php');
        $detail = $this->source('resources/views/marketplace/detail.blade.php');
        self::assertStringContainsString("'freelance'=>'Find freelance offers'", $search);
        self::assertStringContainsString("'freelance' => 'Contact this freelancer'", $detail);
    }
}
