<?php

namespace Tests\Feature;

use App\Models\FeatureFlag;
use App\Models\Policy;
use App\Models\PolicyPublication;
use App\Models\SocialLoginIdentity;
use App\Models\SocialLoginProvider;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Support\Security\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\LinkedInOpenIdProvider;
use Laravel\Socialite\Two\User as ProviderUser;
use Mockery;
use Tests\TestCase;

final class SocialLinkedInLoginTest extends TestCase
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

    private function enableLinkedIn(): void
    {
        SocialLoginProvider::query()->updateOrCreate(['provider'=>'linkedin'],[
            'client_id'=>'linkedin-test','client_secret'=>'testing-secret','enabled'=>true,
        ]);
    }

    private function runLinkedInCallback(?string $email = 'member@example.test', ?bool $verified = true,
        string $subject = 'linkedin-subject', ?string $rawEmail = null)
    {
        $this->enableLinkedIn();
        $profile = (new ProviderUser)->map(['id'=>$subject,'email'=>$email,'name'=>'LinkedIn Member']);
        $profile->setRaw(['sub'=>$subject,'email'=>$rawEmail ?? $email,'email_verified'=>$verified]);
        $driver = Mockery::mock(LinkedInOpenIdProvider::class);
        $driver->shouldReceive('user')->once()->andReturn($profile);
        Socialite::shouldReceive('buildProvider')->once()->andReturn($driver);
        return $this->withSession(['social_login.attempt'=>[
            'provider'=>'linkedin','user'=>null,'at'=>now()->timestamp,
        ]])->get(route('social.callback','linkedin'));
    }

    private function existing(bool $verified = true): User
    {
        $user = User::query()->create(['name'=>'Existing member','email'=>'member@example.test',
            'password'=>'ExistingPasswordSecure!123','role'=>'customer']);
        if ($verified) $user->forceFill(['email_verified_at'=>now()])->save();
        return $user;
    }

    public function test_new_linkedin_member_can_register_after_accepting_terms(): void
    {
        $terms = $this->terms();
        $this->runLinkedInCallback()->assertRedirect(route('social.linkedin.continue'));
        $this->assertGuest();
        $this->assertDatabaseCount('users',1);
        $this->get(route('social.linkedin.continue'))->assertOk()
            ->assertSee('Create account with LinkedIn')->assertSee('member@example.test');
        $this->post(route('social.linkedin.store'),['terms_publication_id'=>$terms->id])
            ->assertSessionHasErrors('terms_accepted');
        $this->post(route('social.linkedin.store'),['terms_publication_id'=>$terms->id,'terms_accepted'=>'1'])
            ->assertRedirect();
        $user = User::query()->where('email','member@example.test')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('customer',$user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('social_login_identities',['user_id'=>$user->id,'provider'=>'linkedin',
            'subject_hash'=>hash('sha256','linkedin-subject')]);
        $this->assertDatabaseHas('policy_acknowledgements',['user_id'=>$user->id,
            'policy_publication_id'=>$terms->id,'record_type'=>'registration_acceptance']);
        $this->assertNull(session('social_linkedin.pending'));
    }

    public function test_existing_account_needs_mailbox_proof_and_keeps_two_factor(): void
    {
        $user = $this->existing();
        UserTwoFactor::query()->create(['user_id'=>$user->id,'secret'=>Totp::createSecret(),
            'enabled_at'=>now(),'recovery_hashes'=>[]]);
        $this->runLinkedInCallback()->assertRedirect(route('social.linkedin.continue'));
        $this->assertGuest();
        $this->assertDatabaseCount('social_login_identities',0);
        $this->post(route('social.linkedin.store'),['confirmation_code'=>'12345678'])->assertSessionHasErrors('social_auth');
        $code = '';
        config(['mail.mailers.array.transport'=>'array']);
        Mail::shouldReceive('getDefaultDriver')->andReturn('array');
        Mail::shouldReceive('raw')->once()->andReturnUsing(function ($body,$callback) use (&$code,$user) {
            preg_match('/\b[0-9]{8}\b/',$body,$matches);
            $code = $matches[0];
            $message = Mockery::mock(\Illuminate\Mail\Message::class);
            $message->shouldReceive('to')->once()->with($user->email)->andReturnSelf();
            $message->shouldReceive('subject')->once()->with('Confirm your Bambodia LinkedIn sign-in')->andReturnSelf();
            $callback($message);
            return Mockery::mock(\Illuminate\Mail\SentMessage::class);
        });
        $this->post(route('social.linkedin.code'))->assertRedirect(route('social.linkedin.continue'));
        $this->assertNotSame($code,session('social_linkedin.pending.code_hash'));
        $this->assertDatabaseCount('social_login_identities',0);
        $this->post(route('social.linkedin.store'),['confirmation_code'=>$code])
            ->assertRedirect(route('login.two-factor'))->assertSessionHas('twofactor.pending_id',$user->id);
        $this->assertGuest();
        $this->assertDatabaseHas('social_login_identities',['user_id'=>$user->id,'provider'=>'linkedin']);
        $this->assertNull(session('social_linkedin.pending'));
        $this->assertDatabaseCount('users',1);
    }

    public function test_unverified_local_email_is_not_automatically_linked(): void
    {
        $user = $this->existing(false);
        $this->runLinkedInCallback()->assertRedirect(route('social.linkedin.continue'));
        $this->get(route('social.linkedin.continue'))->assertOk()->assertSee('Confirm with password')->assertDontSee('Send confirmation code');
        $this->post(route('social.linkedin.code'))->assertRedirect(route('login'));
        $this->post(route('social.linkedin.store'),['current_password'=>'wrong'])->assertSessionHasErrors('social_auth');
        $this->assertGuest();
        $this->assertDatabaseCount('social_login_identities',0);
        $this->post(route('social.linkedin.store'),['current_password'=>'ExistingPasswordSecure!123'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('social_login_identities',['user_id'=>$user->id,'provider'=>'linkedin']);
    }

    public function test_existing_different_linkedin_subject_cannot_be_replaced(): void
    {
        $user = $this->existing();
        SocialLoginIdentity::query()->create(['user_id'=>$user->id,'provider'=>'linkedin',
            'subject_hash'=>hash('sha256','original-subject')]);
        $this->runLinkedInCallback()->assertRedirect(route('login'))->assertSessionHasErrors('social_auth');
        $this->assertGuest();
        $this->assertSame(hash('sha256','original-subject'),SocialLoginIdentity::query()->sole()->subject_hash);
    }

    public function test_known_subject_signs_in_without_email_or_registration(): void
    {
        $user = $this->existing();
        SocialLoginIdentity::query()->create(['user_id'=>$user->id,'provider'=>'linkedin',
            'subject_hash'=>hash('sha256','linkedin-subject')]);
        FeatureFlag::query()->updateOrCreate(['key'=>'registration'],['enabled'=>false]);
        $this->runLinkedInCallback(email:null,verified:null)->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_new_unverified_provider_email_is_rejected(): void
    {
        $this->terms();
        $this->runLinkedInCallback(verified:false)->assertRedirect(route('login'))->assertSessionHasErrors('social_auth');
        $this->assertGuest();
        $this->assertDatabaseCount('users',1);
    }

    public function test_disabled_registration_and_expired_pending_cannot_create_accounts(): void
    {
        $this->terms();
        $this->runLinkedInCallback()->assertRedirect(route('social.linkedin.continue'));
        $this->travel(11)->minutes();
        $this->get(route('social.linkedin.continue'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseCount('users',1);
    }

    public function test_linkedin_label_is_consistent_on_login_and_registration(): void
    {
        $this->enableLinkedIn();
        $this->get('/login')->assertOk()->assertSee('Sign in with LinkedIn');
        $this->get('/register')->assertOk()->assertSee('Sign in with LinkedIn');
    }
}
