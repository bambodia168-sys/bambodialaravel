<?php

namespace Tests\Feature;

use App\Models\MarketplaceListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BambodiaFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'customer'): User
    {
        return User::query()->create([
            'name' => 'Account Owner',
            'email' => uniqid('account', true).'@example.test',
            'password' => 'SecurePasswordOne!',
            'role' => $role,
        ]);
    }

    public function test_public_user_identifiers_are_unique_eight_digit_values(): void
    {
        $first = $this->user();
        $second = $this->user();
        $this->assertMatchesRegularExpression('/^\d{8}$/', $first->public_id);
        $this->assertNotSame($first->public_id, $second->public_id);
    }

    public function test_registration_creates_customer_account_not_administrator(): void
    {
        $response = $this->post('/register', [
            'name' => 'New Customer',
            'email' => 'new@example.test',
            'password' => 'SecurePasswordOne!',
            'password_confirmation' => 'SecurePasswordOne!',
        ]);
        $response->assertRedirect();
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('customer', $user->role);
        $this->assertAuthenticatedAs($user);
    }

    public function test_customer_cannot_access_super_admin_manager(): void
    {
        $this->actingAs($this->user())->get('/dashboard/user-manager')->assertForbidden();
    }

    public function test_user_manager_paginates_ten_rows(): void
    {
        $admin = $this->user('super_admin');
        for ($i = 0; $i < 12; $i++) {
            $this->user();
        }
        $response = $this->actingAs($admin)->get('/dashboard/user-manager');
        $response->assertOk();
        $response->assertViewHas('users', fn ($paginator) => $paginator->count() === 10 && $paginator->total() === 13);
    }

    public function test_creator_submission_remains_unpublished_until_approved(): void
    {
        $creator = $this->user('freelancer');
        $response = $this->actingAs($creator)->post('/studio/listings', [
            'type' => 'project',
            'title' => 'Original Website Template',
            'description' => str_repeat('A commercially useful original website layout. ', 2),
        ]);
        $response->assertRedirect();
        $listing = MarketplaceListing::firstOrFail();
        $this->assertSame('pending', $listing->status);
        $this->assertMatchesRegularExpression('/^\d{9}$/', $listing->public_id);
        $this->get('/search')->assertDontSee('Original Website Template');
    }

    public function test_review_approval_publishes_listing_in_search_results(): void
    {
        $creator = $this->user('freelancer');
        $reviewer = $this->user('admin');
        $listing = MarketplaceListing::query()->create([
            'user_id' => $creator->id,
            'type' => 'service',
            'title' => 'Professional Website Audit',
            'description' => str_repeat('Inspection and improvement recommendations. ', 2),
            'status' => 'pending',
        ]);
        $this->actingAs($reviewer)->patch('/dashboard/review-queue/'.$listing->id, [
            'decision' => 'published',
        ])->assertRedirect();
        $this->get('/search?type=service')->assertSee('Professional Website Audit');
    }
}
