<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyCenterHeroLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_policy_center_has_full_width_hero_with_centered_copy(): void
    {
        $response = $this->get(route('policy-center'));

        $response->assertOk()
            ->assertSee('class="policy-center-premium policy-index"', false)
            ->assertSee('class="policy-hero" aria-labelledby="policy-center-heading"', false)
            ->assertSee('class="container policy-hero-inner"', false)
            ->assertSee('class="policy-hero-copy"', false)
            ->assertSee('Bambodia Policies')
            ->assertSee('<h1 id="policy-center-heading">Policy Center</h1>', false)
            ->assertSee('Understand your rights, responsibilities, and the commitments that guide the Bambodia marketplace.')
            ->assertSee('class="policy-trust-shield" aria-hidden="true"', false)
            ->assertSee('class="container inner-page policy-directory-content"', false)
            ->assertSee('Search policies')
            ->assertSee('Explore our policies');

        $html = $response->getContent();
        $heroStart = strpos($html, '<section class="policy-hero"');
        $directoryStart = strpos($html, '<div class="container inner-page policy-directory-content">');
        $this->assertNotFalse($heroStart);
        $this->assertNotFalse($directoryStart);
        $this->assertGreaterThan($heroStart, $directoryStart);
        $this->assertStringNotContainsString('class="container inner-page policy-index policy-center-premium"', $html);

        $styles = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.policy-center-premium.policy-index{max-width:none', $styles);
        $this->assertStringContainsString('.policy-center-premium .policy-hero{position:relative;isolation:isolate;overflow:hidden;width:100%;max-width:none;margin:0', $styles);
        $this->assertStringContainsString('.policy-center-premium .policy-hero-copy{position:relative;z-index:1;display:flex;flex-direction:column;align-items:center', $styles);
    }
}
