<?php

namespace Tests\Feature;

use App\Models\FeatureFlag;
use App\Models\Policy;
use App\Models\PolicyPublication;
use App\Models\SocialLoginIdentity;
use App\Models\SocialLoginProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as ProviderUser;
use Mockery;
use Tests\TestCase;

final class SocialGoogleRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function terms(): PolicyPublication
    {
        $admin = User::query()->create([
            'name' => 'Policy Admin',
            'email' => 'policy-admin@example.test',
            'password' => 'PolicyTestingPassword!123',
            'role' => 'super_admin',
        ]);
        $policy = Policy::query()->create([
            'title' => 'Terms of Service',
            'category' => 'terms',
            'status' => 'published',
            'jurisdiction' => 'Cambodia',
            'body' => 'Bambodia terms for social account registration and marketplace usage.',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        return PolicyPublication::query()->create([
            'policy_id' => $policy->id,
            'revision' => 1,
            'title' => 'Terms of Service',
            'category' => 'terms',
            'jurisdiction' => 'Cambodia',
            'body_snapshot' => $policy->body,
            'sha256' => PolicyPublication::digestFor($policy),
            'published_by' => $admin->id,
            'created_at' => now(),
        ]);
    }

    private function runGoogleCallback(
        string $email = 'new-member@gmail.com',
        bool $verified = true,
        ?string $rawEmail = null,
        string $subject = 'google-new-subject'
    ): void {
        SocialLoginProvider::query()->updateOrCreate(['provider' => 'google'], [
            'client_id' => 'testing.apps.googleusercontent.com',
            'client_secret' => 'testing-secret',
            'enabled' => true,
        ]);
        $profile = new ProviderUser;
        $profile->map(['id' => $subject, 'email' => $email, 'name' => 'New Google Member']);
        $profile->setRaw([
            'sub' => $subject,
            'email' => $rawEmail ?? $email,
            'email_verified' => $verified,
        ]);
        $driver = Mockery::mock(GoogleProvider::class);
        $driver->shouldReceive('user')->once()->andReturn($profile);
        Socialite::shouldReceive('buildProvider')->once()->andReturn($driver);

        $this->withSession(['social_login.attempt' => [
            'provider' => 'google', 'user' => null, 'at' => now()->timestamp,
        ]])->get(route('social.callback', 'google'))
            ->assertRedirect($verified && ($rawEmail === null || $rawEmail === $email)
                ? route('social.google.registration') : route('login'));
    }

    public function test_new_google_user_must_accept_terms_before_registration(): void
    {
        $terms = $this->terms();
        $this->runGoogleCallback();
        $this->assertDatabaseCount('users', 1); // policy admin only
        $this->assertGuest();

        $this->get(route('social.google.registration'))->assertOk()
            ->assertSee('new-member@gmail.com')
            ->assertSee('terms_accepted')
            ->assertSee('Create account with Google')
            ->assertSee('class="social-auth-identity"', false)
            ->assertSee('class="social-auth-identity-icon"', false)
            ->assertSee('class="social-auth-identity-details"', false);

        $this->post(route('social.google.registration.store'), [
            'terms_publication_id' => $terms->id,
        ])->assertSessionHasErrors('terms_accepted');
        $this->assertDatabaseCount('users', 1);

        $this->post(route('social.google.registration.store'), [
            'terms_publication_id' => $terms->id,
            'terms_accepted' => '1',
        ])->assertRedirect();

        $user = User::query()->where('email', 'new-member@gmail.com')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('customer', $user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('social_login_identities', [
            'provider' => 'google', 'user_id' => $user->id,
            'subject_hash' => hash('sha256', 'google-new-subject'),
        ]);
        $this->assertDatabaseHas('policy_acknowledgements', [
            'user_id' => $user->id,
            'policy_publication_id' => $terms->id,
            'record_type' => 'registration_acceptance',
        ]);
        $this->assertDatabaseCount('users', 2);
        $this->assertNull(session('social_google.registration'));
    }

    public function test_verified_google_custom_domain_can_create_new_account_without_email_autolink(): void
    {
        $terms = $this->terms();
        $this->runGoogleCallback(email: 'new-member@example.test');
        $this->post(route('social.google.registration.store'), [
            'terms_publication_id' => $terms->id, 'terms_accepted' => '1',
        ])->assertRedirect();
        $this->assertAuthenticatedAs(User::query()->where('email', 'new-member@example.test')->sole());
    }

    public function test_expired_google_registration_cannot_create_account(): void
    {
        $terms = $this->terms();
        $this->runGoogleCallback();
        $this->travel(11)->minutes();
        $this->get(route('social.google.registration'))->assertRedirect(route('login'));
        $this->post(route('social.google.registration.store'), [
            'terms_publication_id' => $terms->id, 'terms_accepted' => '1',
        ])->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 1);
        $this->assertGuest();
    }

    public function test_unverified_email_is_not_eligible_for_google_registration(): void
    {
        $this->terms();
        $this->runGoogleCallback(verified: false);
        $this->assertGuest();
        $this->get(route('social.google.registration'))->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_inconsistent_google_email_is_not_eligible_for_registration(): void
    {
        $this->terms();
        $this->runGoogleCallback(rawEmail: 'different@gmail.com');
        $this->assertGuest();
        $this->get(route('social.google.registration'))->assertRedirect(route('login'));
    }

    public function test_existing_account_cannot_be_overwritten_or_duplicated(): void
    {
        $terms = $this->terms();
        User::query()->create([
            'name' => 'Already registered',
            'email' => 'new-member@example.test',
            'password' => 'ExistingPasswordSecure!123',
            'role' => 'customer',
        ]);
        // Deliberately skip callback: simulate an email registered while a
        // legitimate Google registration confirmation was already pending.
        $this->withSession(['social_google.registration' => [
            'email' => 'new-member@example.test',
            'name' => 'New Google Member',
            'subject_hash' => hash('sha256', 'google-new-subject'),
            'at' => now()->timestamp,
        ]])->post(route('social.google.registration.store'), [
            'terms_publication_id' => $terms->id,
            'terms_accepted' => '1',
        ])->assertRedirect(route('login'))->assertSessionHasErrors('social_auth');

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('social_login_identities', 0);
        $this->assertGuest();
    }

    public function test_existing_google_subject_cannot_be_registered_to_a_second_account(): void
    {
        $terms = $this->terms();
        $existing = User::query()->create([
            'name' => 'Existing Google owner',
            'email' => 'existing@gmail.com',
            'password' => 'ExistingPasswordSecure!123',
        ]);
        SocialLoginIdentity::query()->create([
            'user_id' => $existing->id,
            'provider' => 'google',
            'subject_hash' => hash('sha256', 'google-new-subject'),
        ]);
        $this->withSession(['social_google.registration' => [
            'email' => 'new-member@gmail.com',
            'name' => 'New Google Member',
            'subject_hash' => hash('sha256', 'google-new-subject'),
            'at' => now()->timestamp,
        ]])->post(route('social.google.registration.store'), [
            'terms_publication_id' => $terms->id,
            'terms_accepted' => '1',
        ])->assertRedirect(route('login'))->assertSessionHasErrors('social_auth');

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('social_login_identities', 1);
    }

    public function test_disabling_public_registration_disables_first_time_google_registration(): void
    {
        $this->terms();
        FeatureFlag::query()->create(['key' => 'registration', 'enabled' => false]);
        $this->get(route('social.google.registration'))->assertNotFound();
    }
}
