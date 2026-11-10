<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserTwoFactor;
use App\Support\Security\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AccountTwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD='TwoFactorTestingPassword123!';

    private function member(string $role='customer'): User
    {
        return User::query()->create([
            'name'=>'Account 2FA Test',
            'email'=>uniqid($role,true).'@example.test',
            'password'=>self::PASSWORD,
            'role'=>$role,
        ]);
    }

    private function enrollment(User $user): array
    {
        $this->actingAs($user)
            ->post(route('account.security.begin'),[
                'current_password'=>self::PASSWORD,
            ])->assertRedirect();
        $secret=(string)session('twofactor.enroll_secret');
        $this->assertNotSame('',$secret);
        $code=Totp::code($secret,intdiv(now()->timestamp,30));
        $this->post(route('account.security.enable'),[
            'code'=>$code,
        ])->assertRedirect(route('account.security'));
        $credential=UserTwoFactor::query()->whereKey($user->id)->firstOrFail();
        $this->assertNotEquals($secret,$credential->getRawOriginal('secret'));
        $this->assertSame($secret,$credential->secret);
        $recovery=session('twofactor.recovery_codes');
        $this->assertCount(8,$recovery);
        $this->assertCount(8,$credential->recovery_hashes);
        $this->assertNotContains($recovery[0],$credential->recovery_hashes);
        return [$credential,$recovery,$secret];
    }

    public function test_rfc6238_totp_algorithm_known_vector(): void
    {
        $secret='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082',Totp::code($secret,1));
    }

    public function test_2fa_is_optional_and_a_normal_login_does_not_change(): void
    {
        $user=$this->member();
        $this->post(route('login.store'),[
            'email'=>$user->email,'password'=>self::PASSWORD,
        ])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('user_two_factors',0);
    }

    public function test_enrollment_requires_current_password_and_a_valid_totp(): void
    {
        $user=$this->member();
        $this->actingAs($user)->get(route('account.security'))->assertOk()
            ->assertSee('Two-factor authentication');
        $this->post(route('account.security.begin'),[
            'current_password'=>'incorrect-password',
        ])->assertSessionHasErrors('current_password');
        $this->assertNull(session('twofactor.enroll_secret'));
        $this->post(route('account.security.begin'),[
            'current_password'=>self::PASSWORD,
        ])->assertRedirect();
        $this->post(route('account.security.enable'),['code'=>'000000'])
            ->assertSessionHasErrors('code');
        $this->assertDatabaseCount('user_two_factors',0);

        [$credential,$recovery]=$this->enrollment($user);
        $this->get(route('account.security'))
            ->assertOk()->assertSee($recovery[0]);
        $this->get(route('account.security'))
            ->assertOk()->assertDontSee($recovery[0]);
        $this->assertSame(8,count($credential->recovery_hashes));
    }

    public function test_password_alone_never_authenticates_an_enrolled_user(): void
    {
        $user=$this->member();
        [$credential,$recovery,$secret]=$this->enrollment($user);
        $this->post(route('logout'));
        $this->post(route('login.store'),[
            'email'=>$user->email,'password'=>self::PASSWORD,'remember'=>'1',
        ])->assertRedirect(route('login.two-factor'));
        $this->assertGuest();
        $this->get(route('login.two-factor'))->assertOk()->assertSee('Verify your sign-in');
        $this->post(route('login.two-factor.verify'),[
            'code'=>'abcdef',
        ])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post(route('login.two-factor.verify'),[
            'code'=>Totp::code($secret,intdiv(now()->timestamp,30)),
        ])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_events',['event'=>'auth.two_factor.challenge.passed']);
        $this->assertNotNull($credential->fresh()->last_counter);
    }

    public function test_recovery_code_can_only_be_used_once(): void
    {
        $user=$this->member();
        [$credential,$codes]=$this->enrollment($user);
        $this->post(route('logout'));
        $this->post(route('login.store'),[
            'email'=>$user->email,'password'=>self::PASSWORD,
        ])->assertRedirect(route('login.two-factor'));

        $this->post(route('login.two-factor.verify'),['code'=>$codes[0]])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertCount(7,$credential->fresh()->recovery_hashes);

        $this->post(route('logout'));
        $this->post(route('login.store'),[
            'email'=>$user->email,'password'=>self::PASSWORD,
        ])->assertRedirect(route('login.two-factor'));
        $this->post(route('login.two-factor.verify'),[
            'code'=>$codes[0],
        ])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->post(route('login.two-factor.verify'),[
            'code'=>$codes[1],
        ])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertCount(6,$credential->fresh()->recovery_hashes);
    }

    public function test_disable_requires_password_and_second_factor(): void
    {
        $user=$this->member();
        [$credential,$codes]=$this->enrollment($user);
        $this->post(route('account.security.disable'),[
            'current_password'=>'bad-password','code'=>$codes[0],
        ])->assertSessionHasErrors('current_password');
        $this->assertDatabaseHas('user_two_factors',['user_id'=>$user->id]);

        $this->post(route('account.security.disable'),[
            'current_password'=>self::PASSWORD,
            'code'=>$codes[0],
        ])->assertRedirect();
        $this->assertDatabaseMissing('user_two_factors',['user_id'=>$user->id]);
        $this->assertDatabaseHas('audit_events',['event'=>'auth.two_factor.disabled']);
    }

    public function test_challenge_cannot_be_visited_without_password_step_or_after_expiration(): void
    {
        $this->get(route('login.two-factor'))->assertRedirect(route('login'));
        $this->post(route('login.two-factor.verify'),[
            'code'=>'123456',
        ])->assertRedirect(route('login'));
        $user=$this->member();
        $this->withSession([
            'twofactor.pending_id'=>$user->id,
            'twofactor.pending_at'=>now()->subMinutes(8)->timestamp,
        ])->get(route('login.two-factor'))->assertRedirect(route('login'));
    }

    public function test_two_factor_settings_are_isolated_to_owner(): void
    {
        $owner=$this->member('customer');
        $other=$this->member('freelancer');
        $this->enrollment($owner);
        $this->actingAs($other)->get(route('account.security'))->assertOk()
            ->assertDontSee('Enabled since');
        $this->assertDatabaseMissing('user_two_factors',['user_id'=>$other->id]);
    }
}
