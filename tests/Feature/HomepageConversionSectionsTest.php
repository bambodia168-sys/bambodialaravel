<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageConversionSectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_homepage_contains_correct_join_and_sign_in_cta(): void
    {
        $response=$this->get('/')->assertOk()
            ->assertSee('id="bambodia-join"',false)
            ->assertSee('id="bambodia-join-title"',false)
            ->assertSee('Join Free')
            ->assertSee('Sign In')
            ->assertSee('href="'.route('register').'"',false)
            ->assertSee('href="'.route('login').'"',false)
            ->assertDontSee('One platform, different workspaces');

        $this->assertSame(1,substr_count($response->getContent(),'id="bambodia-join"'));
    }

    public function test_advantage_has_three_grounded_cards_and_preserves_existing_homepage_sections(): void
    {
        $response=$this->get('/')->assertOk()
            ->assertSee('id="bambodia-advantage"',false)
            ->assertSee('THE BAMBODIA ADVANTAGE')
            ->assertSee('Reviewed Marketplace Listings')
            ->assertSee('Public Creator Portfolios')
            ->assertSee('Controlled Job Proposals')
            ->assertSee('id="bambodia-jobs"',false)
            ->assertSee('Find the work that fits')
            ->assertDontSee('Instant KHQR &amp; Bakong Escrow')
            ->assertDontSee('Enforceable digital contracts');

        $this->assertSame(3,substr_count($response->getContent(),'class="bambodia-advantage-card"'));
    }

    public function test_authenticated_cta_routes_to_account_and_job_board(): void
    {
        $user=User::query()->create([
            'name'=>'Home Account Tester',
            'email'=>'home-cta-account@example.test',
            'password'=>'SafeAccountPassword123!',
            'role'=>'customer',
        ]);
        $this->actingAs($user)->get('/')->assertOk()
            ->assertSee('My Account')
            ->assertSee('Find Jobs')
            ->assertSee('href="'.route('account').'"',false)
            ->assertSee('href="'.route('jobs').'"',false)
            ->assertDontSee('Join Free');
    }
}
