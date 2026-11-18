<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\SocialLoginIdentity;
use App\Models\SocialLoginProvider;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Support\Security\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as ProviderUser;
use Mockery;
use Tests\TestCase;

class SystemConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_navigation_has_new_system_group_and_security_controls_are_restricted(): void
    {
        $this->actingAs($this->member('staff'))->get(route('dashboard.system-security.index'))->assertForbidden();
        $this->actingAs($this->member('admin'))->get(route('dashboard.social-auth.index'))->assertForbidden();
        $this->patch(route('dashboard.system-security.update', 'passkeys'), ['enabled' => 1])->assertForbidden();

        $this->actingAs($this->member('super_admin'))->get(route('dashboard.system-settings'))->assertOk()
            ->assertSeeInOrder(['Assets & Communication', 'System Configuration', 'Basic Settings', 'Cloud Storage', 'SMTP', 'AI Draft Studio', 'Social Auth', 'Assistance'], false)
            ->assertSee('<h1>Basic Settings</h1>', false);
        $this->get(route('dashboard.system-security.index'))->assertOk()->assertSee('role="switch"', false);
        foreach (['right_click', 'content_protector', 'selection_protector'] as $key) {
            $this->patch(route('dashboard.system-security.update', $key), ['enabled' => 1])->assertRedirect();
        }
        $this->get('/')->assertOk()
            ->assertSee('data-public-content-controls="right_click content_protector selection_protector"', false)
            ->assertSee('data-protected-content', false);
        $this->patch(route('dashboard.system-security.update', 'right_click'), ['enabled' => 0])->assertRedirect();
        $this->get('/')->assertOk()->assertSee('data-public-content-controls="content_protector selection_protector"', false);
        $this->patch(route('dashboard.system-security.update', 'unknown'), ['enabled' => 1])->assertNotFound();
    }

    public function test_stopping_two_factor_enrollment_preserves_existing_account_challenges(): void
    {
        $this->state('two_factor_enrollment', false);
        $member = $this->member('customer');
        $this->actingAs($member)->get(route('account.security'))->assertOk()->assertSee('New two-factor setup is currently disabled.');
        $this->post(route('account.security.begin'), ['current_password' => 'SystemTestPassword123!'])->assertForbidden();

        $credential = UserTwoFactor::query()->create([
            'user_id' => $member->id, 'secret' => Totp::createSecret(), 'enabled_at' => now(), 'recovery_hashes' => [],
        ]);
        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => $member->email, 'password' => 'SystemTestPassword123!'])
            ->assertRedirect(route('login.two-factor'));
        $this->assertGuest();
        $this->assertModelExists($credential);
    }

    public function test_social_setup_encrypts_credentials_and_only_enabled_providers_appear(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('Continue with Google')->assertDontSee('Sign in with a passkey');
        $this->get(route('social.start', 'google'))->assertNotFound();
        $page = route('dashboard.social-auth.index');
        $this->actingAs($this->member('super_admin'))->get($page)->assertOk()
            ->assertSee('Google Login')->assertSee('Facebook Login')->assertSee('LinkedIn Login')->assertSee('GitHub Login');
        $this->from($page)->put(route('dashboard.social-auth.configure', 'google'), ['client_id' => 'fixture'])
            ->assertSessionHasErrors('client_secret');
        $this->put(route('dashboard.social-auth.configure', 'google'), [
            'client_id' => 'fixture', 'client_secret' => 'private-social-fixture',
        ])->assertRedirect();
        $this->assertNotSame('private-social-fixture', DB::table('social_login_providers')->where('provider', 'google')->value('client_secret'));
        $this->get($page)->assertOk()->assertDontSee('private-social-fixture');
        $this->patch(route('dashboard.social-auth.status', 'google'), ['enabled' => 1])->assertRedirect();
        $this->post(route('logout'));
        $this->get('/login')->assertOk()->assertSee('Continue with Google')->assertDontSee('Continue with Facebook');

        $response = $this->get(route('social.start', 'google'))->assertRedirect();
        $this->assertStringStartsWith('https://accounts.google.com/', $response->headers->get('Location'));
        $this->get(route('social.callback', ['provider' => 'google', 'state' => 'wrong', 'code' => 'unused']))
            ->assertRedirect(route('login'))->assertSessionHasErrors('social_auth');
        $this->assertGuest();
    }

    public function test_social_requires_linking_and_keeps_two_factor_at_login(): void
    {
        $user = $this->member('customer');
        SocialLoginProvider::query()->create(['provider' => 'google', 'client_id' => 'test', 'client_secret' => 'private', 'enabled' => true]);
        $driver = Mockery::mock(GoogleProvider::class);
        $driver->shouldReceive('user')->times(3)->andReturn((new ProviderUser)->map(['id' => 'google-subject', 'email' => $user->email]));
        $driver->shouldReceive('redirect')->once()->andReturn(redirect()->away('https://accounts.google.com/'));
        Socialite::shouldReceive('buildProvider')->andReturn($driver);
        $callback = route('social.callback', 'google');
        $attempt = ['provider' => 'google', 'user' => null, 'at' => now()->timestamp];
        $this->withSession(['social_login.attempt' => $attempt])->get($callback)
            ->assertRedirect(route('login'))->assertSessionHasErrors('social_auth');
        $this->assertGuest();

        $this->actingAs($user)->from(route('account.security'))->post(route('account.social.connect', 'google'), ['current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');
        $this->post(route('account.social.connect', 'google'), ['current_password' => 'SystemTestPassword123!'])
            ->assertRedirect('https://accounts.google.com/');
        $this->get($callback)->assertRedirect(route('account.security'));
        $this->assertDatabaseHas('social_login_identities', ['user_id' => $user->id, 'provider' => 'google']);
        $this->assertSame(hash('sha256', 'google-subject'), SocialLoginIdentity::query()->sole()->subject_hash);

        UserTwoFactor::query()->create(['user_id' => $user->id, 'secret' => Totp::createSecret(), 'recovery_hashes' => [], 'enabled_at' => now()]);
        $this->post(route('logout'));
        $this->withSession(['social_login.attempt' => $attempt])->get($callback)->assertRedirect(route('login.two-factor'))
            ->assertSessionHas('twofactor.pending_id', $user->id);
        $this->assertGuest();
    }

    public function test_passkey_ceremonies_are_feature_gated_and_bound_to_one_session_attempt(): void
    {
        $authOptions = route('login.passkeys.options');
        $login = route('login.passkeys.authenticate');
        $this->postJson($authOptions)->assertNotFound();

        $this->state('passkeys', true);
        $this->get('/login')->assertOk()->assertSee('Sign in with a passkey');
        $this->postJson($authOptions)->assertOk()->assertJsonPath('userVerification', 'required')->assertJsonStructure(['challenge', 'rpId']);
        $this->postJson($login, ['credential' => '{"invalid":true}'])->assertUnprocessable();
        $this->postJson($login, ['credential' => '{"invalid":true}'])->assertUnprocessable()->assertJsonPath('message', 'This passkey request expired. Start again.');

        $this->actingAs($this->member('customer'))->get(route('account.security'))->assertOk()->assertSee('Add passkey');
        $this->postJson(route('account.passkeys.options'), ['current_password' => 'incorrect'])->assertUnprocessable();
        $this->postJson(route('account.passkeys.options'), ['current_password' => 'SystemTestPassword123!'])
            ->assertOk()->assertJsonPath('authenticatorSelection.userVerification', 'required');
    }

    private function member(string $role): User
    {
        return User::query()->create([
            'name' => 'System QA '.$role,
            'email' => uniqid('system-'.$role.'-').'@example.test',
            'role' => $role, 'password' => 'SystemTestPassword123!',
        ]);
    }

    private function state(string $control, bool $enabled): void
    {
        SiteSetting::query()->create(['key' => 'security.'.$control, 'value' => $enabled ? '1' : '0']);
    }
}
