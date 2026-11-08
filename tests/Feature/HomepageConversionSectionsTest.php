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
            ->assertSee('class="marketplace-hero-scene"',false)
            ->assertSee('id="bambodia-join"',false)
            ->assertSee('id="bambodia-join-title"',false)
            ->assertSee('Join Now')
            ->assertSee('Sign In')
            ->assertSee('href="'.route('register').'"',false)
            ->assertSee('href="'.route('login').'"',false)
            ->assertDontSee('One platform, different workspaces');

        $this->assertSame(1,substr_count($response->getContent(),'id="bambodia-join"'));
        $this->assertStringContainsString('<x-icon', file_get_contents(resource_path('views/home.blade.php')));
        $this->assertDontSeeOldJoinLabel($response->getContent());
    }

    private function assertDontSeeOldJoinLabel(string $html): void
    {
        $this->assertStringNotContainsString('Join Free',$html);
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
        $html=$response->getContent();
        $jobs=strpos($html,'id="bambodia-jobs"');
        $advantage=strpos($html,'id="bambodia-advantage"');
        $join=strpos($html,'id="bambodia-join"');
        $this->assertNotFalse($jobs);
        $this->assertNotFalse($advantage);
        $this->assertNotFalse($join);
        $this->assertTrue($jobs<$advantage && $advantage<$join);
        $this->assertSame(1,substr_count($html,'id="bambodia-advantage"'));
        $this->assertStringContainsString('name="user-plus" size="16"',file_get_contents(resource_path('views/home.blade.php')));
        $this->assertStringContainsString('name="log-in" size="16"',file_get_contents(resource_path('views/home.blade.php')));
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
            ->assertDontSee('Join Now');
    }
}
