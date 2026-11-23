<?php

namespace Tests\Feature;

use App\Models\SocialLoginIdentity;
use App\Models\SocialLoginProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as ProviderUser;
use Mockery;
use Tests\TestCase;

final class GoogleSignInRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function existingUser(string $email = 'member@gmail.com', string $status = 'active'): User
    {
        return User::query()->create([
            'name' => 'Existing Customer',
            'email' => $email,
            'password' => 'SecureExistingPassword!123',
            'role' => 'customer',
            'account_status' => $status,
        ]);
    }

    private function callback(
        string $subject = 'google-subject-123',
        string $email = 'member@gmail.com',
        bool $verified = true,
        ?string $rawEmail = null
    ): TestResponse {
        SocialLoginProvider::query()->updateOrCreate(['provider' => 'google'], [
            'client_id' => 'test.apps.googleusercontent.com',
            'client_secret' => 'test-google-client-secret',
            'enabled' => true,
        ]);

        $profile = new ProviderUser;
        $profile->map(['id' => $subject, 'name' => 'Google Member', 'email' => $email]);
        $profile->setRaw(['sub' => $subject, 'email' => $rawEmail ?? $email, 'email_verified' => $verified]);
        $driver = Mockery::mock(GoogleProvider::class);
        $driver->shouldReceive('user')->once()->andReturn($profile);
        Socialite::shouldReceive('buildProvider')->once()->andReturn($driver);

        return $this->withSession(['social_login.attempt' => [
            'provider' => 'google', 'user' => null, 'at' => now()->timestamp,
        ]])->get(route('social.callback', ['provider' => 'google']));
    }

    public function test_verified_gmail_restores_existing_account_without_a_prior_link(): void
    {
        $user = $this->existingUser();

        $this->callback()->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('social_login_identities', [
            'user_id' => $user->id,
            'provider' => 'google',
            'subject_hash' => hash('sha256', 'google-subject-123'),
        ]);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_recovered_google_identity_is_reused_on_later_signins(): void
    {
        $user = $this->existingUser();
        $this->callback()->assertRedirect();
        $this->post(route('logout'))->assertRedirect();

        $this->callback()->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('social_login_identities', 1);
    }

    public function test_unverified_google_email_cannot_take_over_existing_account(): void
    {
        $this->existingUser();
        $this->callback(verified: false)->assertRedirect(route('login'))
            ->assertSessionHasErrors('social_auth');
        $this->assertGuest();
        $this->assertDatabaseCount('social_login_identities', 0);
    }

    public function test_google_profile_email_mismatch_is_not_trusted(): void
    {
        $this->existingUser();
        $this->callback(rawEmail: 'someone-else@gmail.com')->assertRedirect(route('login'))
            ->assertSessionHasErrors('social_auth');
        $this->assertGuest();
        $this->assertDatabaseCount('social_login_identities', 0);
    }

    public function test_google_email_on_non_authoritative_domain_requires_explicit_link(): void
    {
        $this->existingUser('member@example.test');
        $this->callback(email: 'member@example.test')->assertRedirect(route('login'))
            ->assertSessionHasErrors('social_auth');
        $this->assertGuest();
        $this->assertDatabaseCount('social_login_identities', 0);
    }

    public function test_new_google_email_does_not_silently_create_account_without_terms_consent(): void
    {
        $this->callback()->assertRedirect(route('login'))
            ->assertSessionHasErrors('social_auth');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_inactive_accounts_cannot_be_recovered(): void
    {
        $this->existingUser(status: 'suspended');
        $this->callback()->assertRedirect(route('login'))
            ->assertSessionHasErrors('social_auth');
        $this->assertGuest();
        $this->assertDatabaseCount('social_login_identities', 0);
    }

    public function test_google_subject_cannot_replace_a_different_existing_link(): void
    {
        $user = $this->existingUser();
        SocialLoginIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => 'google',
            'subject_hash' => hash('sha256', 'different-google-subject'),
        ]);

        $this->callback()->assertRedirect(route('login'))->assertSessionHasErrors('social_auth');
        $this->assertGuest();
        $this->assertDatabaseCount('social_login_identities', 1);
        $this->assertDatabaseHas('social_login_identities', [
            'user_id' => $user->id,
            'subject_hash' => hash('sha256', 'different-google-subject'),
        ]);
    }

    public function test_existing_link_uses_immutable_google_subject_not_mutable_email(): void
    {
        $user = $this->existingUser();
        SocialLoginIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => 'google',
            'subject_hash' => hash('sha256', 'google-subject-123'),
        ]);

        // Existing linkage continues to work even if the Google email changes.
        $this->callback(email: 'renamed@example.test', verified: false)->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('social_login_identities', 1);
    }
}
