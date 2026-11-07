<?php

namespace Tests\Feature;

use App\Models\Policy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role = 'customer'): User
    {
        return User::query()->create([
            'name' => 'Feedback Tester', 'email' => uniqid('feedback', true).'@example.test',
            'password' => 'TestPassword123!', 'role' => $role,
        ]);
    }

    public function test_account_success_is_shown_once_and_not_inline(): void
    {
        $response = $this->actingAs($this->member())
            ->withSession(['status' => 'Your optional acknowledgement was recorded.'])
            ->get(route('account.policy-receipts'));
        $response->assertOk()->assertSee('data-feedback-region', false)->assertSee('data-global-toast', false)
            ->assertSee('data-feedback-dismiss', false);
        $this->assertSame(1, substr_count($response->getContent(), 'Your optional acknowledgement was recorded.'));
        $this->assertStringNotContainsString('<div class="notice success"', $response->getContent());
    }

    public function test_public_and_auth_use_the_same_feedback_component(): void
    {
        $public = $this->withSession(['status' => 'Marketplace operation completed.'])->get(route('home'));
        $public->assertOk()->assertSee('data-feedback-region', false);
        $this->assertSame(1, substr_count($public->getContent(), 'Marketplace operation completed.'));
        $auth = $this->withSession(['status' => 'Account information confirmed.'])->get(route('login'));
        $auth->assertOk()->assertSee('data-feedback-region', false);
        $this->assertSame(1, substr_count($auth->getContent(), 'Account information confirmed.'));
    }

    public function test_duplicate_policy_validation_errors_become_one_confirmation_popup(): void
    {
        $admin = $this->member('super_admin');
        $policy = Policy::query()->create([
            'title' => 'Feedback Review Policy', 'body' => str_repeat('Documented review is required for publication. ', 3),
            'category' => 'terms', 'status' => 'draft',
        ]);
        $url = route('dashboard.policies.edit', $policy);
        $this->actingAs($admin)->from($url)->patch(route('dashboard.policies.update', $policy), [
            'title' => $policy->title, 'body' => $policy->body,
            'category' => 'terms', 'status' => 'published',
        ])->assertRedirect($url)->assertSessionHasErrors('confirm_legal_review');
        // Re-create the redirect's error bag explicitly. A second test request can
        // age Laravel's flashed messages, which is unrelated to toast rendering.
        $message = 'Confirm documented legal review before publishing.';
        $errors = new \Illuminate\Support\ViewErrorBag();
        $errors->put('default', new \Illuminate\Support\MessageBag([
            'status' => [$message],
            'confirm_legal_review' => [$message],
        ]));
        $response = $this->withSession(['errors' => $errors])->get($url);
        $response->assertOk()->assertSee('data-feedback-kind="error"', false);
        $this->assertSame(1, substr_count($response->getContent(), $message));
    }
}
