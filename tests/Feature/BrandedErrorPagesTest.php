<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class BrandedErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_errors_keep_status_headers_and_global_navigation(): void
    {
        Route::get('/error-page-fixture/{code}', fn ($code) => abort((int) $code, 'Internal fixture details', [
            'Retry-After' => '60',
        ]));

        foreach ([400, 401, 402, 403, 405, 409, 410, 419, 422, 429] as $code) {
            $this->get('/error-page-fixture/'.$code)
                ->assertStatus($code)
                ->assertHeader('Retry-After', '60')
                ->assertSee('<header class="site-header">', false)
                ->assertSee('aria-label="'.$code.'"', false)
                ->assertSee('content="noindex,nofollow"', false)
                ->assertDontSee('Internal fixture details');
        }

        $this->getJson('/error-page-fixture/403')->assertForbidden()->assertJsonStructure(['message']);
        $this->get('/builder?type=job')->assertRedirect('/login');
    }

    public function test_server_errors_render_when_the_database_connection_is_unavailable(): void
    {
        $this->app['config']->set('app.debug', false);
        $connection = DB::getDefaultConnection();
        DB::setDefaultConnection('missing_error_fixture_connection');
        Route::get('/server-error-fixture/{code}', fn ($code) => abort((int) $code, 'Private origin details', [
            'Retry-After' => '120',
        ]));

        try {
            foreach ([500, 502, 503, 504] as $code) {
                $this->get('/server-error-fixture/'.$code)
                    ->assertStatus($code)
                    ->assertHeader('Retry-After', '120')
                    ->assertSee('class="site-error-standalone"', false)
                    ->assertSee('aria-label="'.$code.'"', false)
                    ->assertDontSee('Private origin details');
            }
        } finally {
            DB::setDefaultConnection($connection);
        }
    }
}
