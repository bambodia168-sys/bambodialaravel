<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffWorkspaceNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_has_a_support_only_dashboard_without_administration_labels(): void
    {
        $user = $this->syntheticUser('staff');

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('Support operations')
            ->assertSee('Staff Workspace')
            ->assertSee('Support overview')
            ->assertDontSee('Administration')
            ->assertDontSee('Admin Dashboard');

        $this->get('/account')
            ->assertOk()
            ->assertSee('Staff Workspace')
            ->assertDontSee('Administration');

        $this->get('/dashboard/preview-content')
            ->assertForbidden();
    }

    public function test_admin_retains_administration_label_but_not_super_admin_preview_permissions(): void
    {
        $user = $this->syntheticUser('admin');

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('Administration')
            ->assertSee('Dashboard overview')
            ->assertDontSee('Staff Workspace');

        $this->get('/dashboard/preview-content')
            ->assertForbidden();
    }

    public function test_super_admin_retains_administration_label_and_preview_review_access(): void
    {
        $user = $this->syntheticUser('super_admin');

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('Administration')
            ->assertDontSee('Staff Workspace');

        $this->get('/dashboard/preview-content')
            ->assertOk()
            ->assertSee('Preview Visibility Review');
    }

    public function test_subscriber_cannot_access_either_staff_or_admin_dashboard(): void
    {
        $user = $this->syntheticUser('subscriber');

        $this->actingAs($user)->get('/dashboard')
            ->assertForbidden();

        $this->get('/account')
            ->assertOk()
            ->assertDontSee('Staff Workspace')
            ->assertDontSee('Administration');
    }

    private function syntheticUser(string $role): User
    {
        return User::query()->create([
            'name' => 'Synthetic Workspace '.$role,
            'email' => str_replace('_', '.', $role).'@example.invalid',
            'password' => 'synthetic-test-secret-only',
            'role' => $role,
            'account_status' => 'active',
        ]);
    }
}
