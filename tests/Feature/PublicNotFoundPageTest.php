<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicNotFoundPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_pages_and_incomplete_license_links_get_branded_html_with_a_404_status(): void
    {
        foreach (['/unavailable-page-example', '/licenses', '/licenses?=999999999-unavailable-license'] as $url) {
            $this->get($url)
                ->assertNotFound()
                ->assertSee('<meta name="robots" content="noindex">', false)
                ->assertSee('id="page-title">Page not found', false)
                ->assertSee('href="/">Back to homepage', false)
                ->assertSee('href="/search">Search marketplace', false)
                ->assertSee('href="/support-center">Support Center', false);
        }
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
