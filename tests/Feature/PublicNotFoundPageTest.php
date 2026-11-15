<?php

namespace Tests\Feature;

use App\Models\FeatureFlag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicNotFoundPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_pages_get_the_global_layout_and_keep_the_404_status(): void
    {
        $this->withoutVite();

        foreach (['/unavailable-page-example', '/licenses', '/licenses?=999999999-unavailable-license'] as $url) {
            $this->get($url)
                ->assertNotFound()
                ->assertSeeInOrder([
                    '<header class="site-header">',
                    'class="site-not-found-mark"',
                    'id="page-title">Page not found',
                    '<footer class="site-footer">',
                ], false)
                ->assertSee('<meta name="robots" content="noindex,follow">', false)
                ->assertSee('Back to homepage')
                ->assertSee(FeatureFlag::isEnabled('marketplace_search') ? 'Search marketplace' : 'Support Center');
        }
    }

    public function test_a_signed_in_visitor_keeps_the_global_account_menu_on_a_404(): void
    {
        $this->withoutVite();
        $visitor = User::query()->create([
            'name' => '404 Browser Visitor',
            'email' => '404-visitor@example.test',
            'password' => 'Isolated404TestPassword42!',
            'role' => 'customer',
        ]);

        $this->actingAs($visitor)->get('/licenses')
            ->assertNotFound()
            ->assertSee('aria-label="Profile menu"', false)
            ->assertSee($visitor->name);
    }

    public function test_missing_api_and_json_requests_keep_the_json_error_response(): void
    {
        $this->get('/api/nonexistent-bambodia-endpoint')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
        $this->getJson('/licenses')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }
}
