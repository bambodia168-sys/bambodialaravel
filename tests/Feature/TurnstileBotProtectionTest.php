<?php

namespace Tests\Feature;

use App\Models\TurnstileBotProtection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TurnstileBotProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role='super_admin'): User
    {
        return User::query()->create([
            'name'=>'Turnstile '.$role,
            'email'=>uniqid($role,true).'@example.test',
            'password'=>'PassphraseForProtectedLogin123!',
            'role'=>$role,
        ]);
    }

    private function configure(): TurnstileBotProtection
    {
        return TurnstileBotProtection::query()->create([
            'id'=>1,
            'site_key'=>'site_key_example_123456789',
            'secret_key'=>'very_secret_value_123456789',
            'is_enabled'=>false,
            'protect_login'=>true,
            'protect_registration'=>true,
            'protect_password_reset'=>true,
        ]);
    }

    private function fakeSiteVerify(string $action='admin_test', ?string $hostname=null, bool $success=true): void
    {
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' =>
                Http::response([
                    'success'=>$success,
                    'hostname'=>$hostname ?? parse_url((string)config('app.url'),PHP_URL_HOST),
                    'action'=>$action,
                ],200),
        ]);
    }

    private function verifiedConfig(): TurnstileBotProtection
    {
        $cfg=$this->configure();
        $cfg->update(['tested_at'=>now()]);
        return $cfg;
    }

    public function test_turnstile_is_unconfigured_and_disabled_by_default_without_changing_auth(): void
    {
        $user=$this->member('customer');
        $this->get(route('login'))->assertOk()->assertDontSee('cf-turnstile');
        $this->post(route('login.store'),[
            'email'=>$user->email,'password'=>'PassphraseForProtectedLogin123!',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_saves_secret_encrypted_and_does_not_activate_by_accident(): void
    {
        $admin=$this->member();
        $this->actingAs($admin)->get(route('dashboard.turnstile.index'))
            ->assertOk()->assertSee('Cloudflare Turnstile');

        $this->post(route('dashboard.turnstile.configure'),[
            'site_key'=>'site_key_example_123456789',
            'secret_key'=>'super_private_example_123456789',
        ])->assertRedirect();
        $config=TurnstileBotProtection::current();
        $this->assertNotNull($config);
        $this->assertFalse($config->is_enabled);
        $this->assertNull($config->tested_at);
        $this->assertNotSame('super_private_example_123456789',$config->getRawOriginal('secret_key'));
        $this->get(route('dashboard.turnstile.index'))->assertOk()->assertDontSee('super_private_example_123456789');

        $this->patch(route('dashboard.turnstile.status'),[
            'is_enabled'=>'1','protect_login'=>'1',
            'protect_registration'=>'1','protect_password_reset'=>'1',
        ])->assertStatus(422);
        $this->assertDatabaseHas('audit_events',['event'=>'security.turnstile.configured']);
    }

    public function test_admin_must_complete_live_siteverify_before_enabling_protection(): void
    {
        $this->actingAs($this->member());
        $this->configure();
        $this->post(route('dashboard.turnstile.verify'))->assertSessionHasErrors('turnstile');
        $this->assertNull(TurnstileBotProtection::current()->tested_at);

        $this->fakeSiteVerify();
        $this->post(route('dashboard.turnstile.verify'),[
            'cf-turnstile-response'=>'admin_challenge_token_abc',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertNotNull(TurnstileBotProtection::current()->tested_at);
        $this->patch(route('dashboard.turnstile.status'),[
            'is_enabled'=>'1','protect_login'=>'1',
            'protect_registration'=>'1','protect_password_reset'=>'1',
        ])->assertRedirect()->assertSessionHas('status');
        $this->assertTrue(TurnstileBotProtection::current()->is_enabled);
        $this->assertDatabaseHas('audit_events',['event'=>'security.turnstile.tested']);
    }

    public function test_active_protection_blocks_missing_tokens_and_accepts_real_matching_siteverify_response(): void
    {
        $user=$this->member('customer');
        $cfg=$this->verifiedConfig();
        $cfg->update(['is_enabled'=>true]);

        $this->get(route('login'))->assertOk()
            ->assertSee('cf-turnstile')
            ->assertSee('data-action="login"',false)
            ->assertSee('site_key_example_123456789');

        $this->post(route('login.store'),[
            'email'=>$user->email,'password'=>'PassphraseForProtectedLogin123!',
        ])->assertSessionHasErrors('turnstile');
        $this->assertGuest();

        $this->fakeSiteVerify('login');
        $this->post(route('login.store'),[
            'email'=>$user->email,'password'=>'PassphraseForProtectedLogin123!',
            'cf-turnstile-response'=>'valid_one_use_token',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_action_or_hostname_cannot_bypass_enabled_protection(): void
    {
        $user=$this->member('customer');
        $cfg=$this->verifiedConfig();
        $cfg->update(['is_enabled'=>true]);

        foreach ([['register',null],['login','other-domain.example'],['login',null]] as $i=>$scenario) {
            $this->fakeSiteVerify($scenario[0],$scenario[1],$i!==2);
            $this->post(route('login.store'),[
                'email'=>$user->email,'password'=>'PassphraseForProtectedLogin123!',
                'cf-turnstile-response'=>'invalid_or_mismatched_token',
            ])->assertSessionHasErrors('turnstile');
            $this->assertGuest();
        }
    }

    public function test_provider_outage_fails_closed_and_reset_requests_are_protected(): void
    {
        $cfg=$this->verifiedConfig();
        $cfg->update(['is_enabled'=>true]);
        $this->post(route('password.email'),[
            'email'=>'anyone@example.test',
        ])->assertSessionHasErrors('turnstile');

        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify'=>Http::response('Unavailable',503),
        ]);
        $user=$this->member('customer');
        $this->post(route('login.store'),[
            'email'=>$user->email,
            'password'=>'PassphraseForProtectedLogin123!',
            'cf-turnstile-response'=>'candidate_token',
        ])->assertSessionHasErrors('turnstile');
        $this->assertGuest();
    }

    public function test_disabling_and_removing_protection_restores_existing_auth_without_affecting_throttling(): void
    {
        $admin=$this->member();
        $this->actingAs($admin);
        $cfg=$this->verifiedConfig();
        $cfg->update(['is_enabled'=>true]);

        $this->patch(route('dashboard.turnstile.status'),[
            'is_enabled'=>'0','protect_login'=>'1',
            'protect_registration'=>'1','protect_password_reset'=>'1',
        ])->assertRedirect();
        $this->assertFalse(TurnstileBotProtection::current()->is_enabled);
        $this->get(route('login'))->assertDontSee('cf-turnstile');

        $this->delete(route('dashboard.turnstile.destroy'))->assertRedirect();
        $this->assertDatabaseCount('turnstile_bot_protections',0);
    }

    public function test_local_emergency_command_can_disable_bot_challenges_safely(): void
    {
        $config=$this->verifiedConfig();
        $config->update(['is_enabled'=>true]);
        $this->artisan('security:turnstile-disable')->assertSuccessful();
        $this->assertFalse($config->fresh()->is_enabled);
    }

    public function test_staff_admin_and_customer_have_no_access_to_turnstile_credentials(): void
    {
        foreach (['staff','admin','customer'] as $role) {
            $this->actingAs($this->member($role))
                ->get(route('dashboard.turnstile.index'))->assertForbidden();
            $this->post(route('dashboard.turnstile.configure'),[
                'site_key'=>'site_key_example_123456789','secret_key'=>'very_secret_value_123456789',
            ])->assertForbidden();
            $this->post(route('dashboard.turnstile.verify'),[
                'cf-turnstile-response'=>'guess',
            ])->assertForbidden();
            $this->patch(route('dashboard.turnstile.status'),[
                'is_enabled'=>'0','protect_login'=>'0',
                'protect_registration'=>'0','protect_password_reset'=>'0',
            ])->assertForbidden();
        }
    }
}
