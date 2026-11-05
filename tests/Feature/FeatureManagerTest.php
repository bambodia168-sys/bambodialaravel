<?php

namespace Tests\Feature;

use App\Models\FeatureFlag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_renders_and_guest_can_change_language(): void
    {
        $this->get('/')->assertOk();
        $this->post('/locale', ['locale' => 'km'])->assertRedirect();
        $this->get('/')->assertOk()->assertSee('ស្នាដៃឌីជីថល');
        $this->post('/locale', ['locale' => 'unknown'])->assertSessionHasErrors('locale');
    }

    public function test_feature_switches_control_real_routes(): void
    {
        $admin = User::query()->create([
            'name' => 'Administrator',
            'email' => 'admin@example.test',
            'password' => 'LongSecurePassword123!',
            'role' => 'super_admin',
        ]);

        $this->actingAs($admin)->get('/dashboard/feature-manager')->assertOk();
        $registration = FeatureFlag::query()->where('key', 'registration')->firstOrFail();

        $this->patch('/dashboard/feature-manager/'.$registration->id, ['enabled' => '0'])
            ->assertRedirect();

        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'New Account', 'email' => 'new@example.test',
            'password' => 'LongSecurePassword123!',
            'password_confirmation' => 'LongSecurePassword123!',
        ])->assertNotFound();
        $this->get('/login')->assertOk();
    }

    public function test_only_super_admin_can_change_feature_flags(): void
    {
        $customer = User::query()->create([
            'name' => 'Customer',
            'email' => 'customer@example.test',
            'password' => 'LongSecurePassword123!',
            'role' => 'customer',
        ]);

        $this->actingAs($customer)->get('/dashboard/feature-manager')->assertForbidden();
    }
}
