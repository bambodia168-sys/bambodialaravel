<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CreatorApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatorApplicationTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'customer'): User
    {
        return User::query()->create([
            'name' => 'Account Applicant',
            'email' => uniqid('creator', true).'@example.test',
            'password' => 'SecureCreatorPassword123!',
            'role' => $role,
        ]);
    }

    private function details(): array
    {
        return [
            'specialization' => 'Digital theme design',
            'experience' => str_repeat('I create original commercial themes and templates. ', 2),
            'portfolio_url' => 'https://example.org/portfolio',
        ];
    }

    public function test_customer_can_submit_application_but_cannot_publish_before_approval(): void
    {
        $customer = $this->account();
        $this->actingAs($customer)->get('/creator')->assertRedirect('/creator/apply');
        $this->post('/creator/apply', $this->details())->assertRedirect('/creator/apply');

        $application = CreatorApplication::query()->firstOrFail();
        $this->assertSame($customer->id, $application->user_id);
        $this->assertSame('pending', $application->status);
        $this->assertSame('customer', $customer->fresh()->role);
        $this->get('/studio/listings')->assertForbidden();
        $this->post('/creator/apply', $this->details())->assertStatus(409);
        $this->assertDatabaseHas('audit_events', ['event' => 'creator.application.submitted', 'actor_id' => $customer->id]);
    }

    public function test_administrator_approval_transfers_creator_access_with_audit_event(): void
    {
        $customer = $this->account();
        $reviewer = $this->account('admin');
        $application = CreatorApplication::query()->create([
            ...$this->details(),
            'user_id' => $customer->id,
            'status' => 'pending',
        ]);

        $this->actingAs($reviewer)
            ->patch('/dashboard/creator-applications/'.$application->id, [
                'decision' => 'approved',
            ])->assertRedirect();

        $this->assertSame('approved', $application->fresh()->status);
        $this->assertSame('freelancer', $customer->fresh()->role);
        $this->actingAs($customer)->get('/studio/listings')->assertOk();
        $this->get('/creator/apply')->assertRedirect('/studio?='.$customer->public_id);
        $this->assertDatabaseHas('audit_events', ['event' => 'creator.application.approved', 'actor_id' => $reviewer->id]);

        $this->actingAs($reviewer)
            ->patch('/dashboard/creator-applications/'.$application->id, [
                'decision' => 'approved',
            ])->assertStatus(409);
    }

    public function test_rejected_application_can_be_resubmitted_with_feedback(): void
    {
        $customer = $this->account();
        $reviewer = $this->account('admin');
        $this->actingAs($customer)->post('/creator/apply', $this->details())->assertRedirect();

        $application = CreatorApplication::firstOrFail();
        $this->actingAs($reviewer)
            ->patch('/dashboard/creator-applications/'.$application->id, [
                'decision' => 'rejected',
                'review_note' => 'Provide more details about your original design work.',
            ])->assertRedirect();

        $this->assertSame('customer', $customer->fresh()->role);
        $this->actingAs($customer)->get('/creator/apply')
            ->assertOk()->assertSee('Provide more details');
        $this->post('/creator/apply', [
            ...$this->details(),
            'specialization' => 'Original presentation and template design',
        ])->assertRedirect();

        $this->assertSame(1, CreatorApplication::count());
        $this->assertSame('pending', $application->fresh()->status);
        $this->assertNull($application->fresh()->review_note);
    }

    public function test_staff_cannot_approve_and_review_queue_paginates_ten(): void
    {
        $staff = $this->account('staff');
        $admin = $this->account('admin');
        for ($i = 0; $i < 13; $i++) {
            $applicant = $this->account();
            CreatorApplication::query()->create([
                'user_id' => $applicant->id,
                ...$this->details(),
                'status' => 'pending',
            ]);
        }

        $this->actingAs($staff)->get('/dashboard/creator-applications')->assertForbidden();
        $this->actingAs($admin)->get('/dashboard/creator-applications')
            ->assertOk()
            ->assertViewHas('applications', fn ($page) => $page->count() === 10 && $page->total() === 13);
    }

    public function test_password_and_private_user_data_are_never_in_application_review(): void
    {
        $applicant = $this->account();
        $admin = $this->account('admin');
        $this->actingAs($applicant)->post('/creator/apply', $this->details())->assertRedirect();
        $this->actingAs($admin)->get('/dashboard/creator-applications')
            ->assertOk()->assertDontSee('SecureCreatorPassword123!');
    }
}
