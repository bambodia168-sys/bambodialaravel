<?php

namespace Tests\Feature;

use App\Models\AccountNotification;
use App\Models\CommerceGatewaySetting;
use App\Models\CommercePaymentMethod;
use App\Models\User;
use App\Support\BakongTokenRenewal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BakongTokenRenewalTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX = 'https://sit-api-bakong.nbc.gov.kh';
    private const PRODUCTION = 'https://api-bakong.nbc.gov.kh';

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.enabled' => true, 'commerce.live_gateways' => true, 'commerce.bakong' => []]);
        Http::preventStrayRequests();
    }

    public function test_it_alerts_admins_once_before_expiry_without_requesting_an_early_production_token(): void
    {
        $admin = $this->admin();
        $this->gateway(true, now()->addDays(2)->timestamp, null);
        Http::fake();

        $this->artisan('commerce:renew-bakong-token')->expectsOutput('Bakong Production · not due')->assertSuccessful();
        $this->artisan('commerce:renew-bakong-token')->assertSuccessful();

        Http::assertNothingSent();
        $alert = AccountNotification::query()->sole();
        $this->assertSame($admin->id, $alert->user_id);
        $this->assertSame('Bakong Production token expiring', $alert->title);
    }

    public function test_expired_production_token_is_verified_before_encrypted_replacement(): void
    {
        $live = $this->gateway(true, now()->subMinute()->timestamp);
        $sandbox = $this->gateway(false, now()->addDays(30)->timestamp);
        $newToken = $this->token(now()->addDays(90)->timestamp);
        Http::fake([
            self::PRODUCTION.'/v1/renew_token' => Http::response(['responseCode' => 0, 'data' => ['token' => $newToken]]),
            self::PRODUCTION.'/v1/check_bakong_account' => Http::response(['responseCode' => 0]),
        ]);

        $this->artisan('commerce:renew-bakong-token')->expectsOutput('Bakong Production · renewed')->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === self::PRODUCTION.'/v1/renew_token'
            && $request['email'] === 'merchant@example.test' && ! $request->hasHeader('Authorization'));
        Http::assertSent(fn (Request $request) => $request->url() === self::PRODUCTION.'/v1/check_bakong_account'
            && $request->hasHeader('Authorization', 'Bearer '.$newToken) && $request['accountId'] === 'merchant@test');
        $this->assertSame(array_replace($live->credentials, ['token' => $newToken]), $live->fresh()->credentials);
        $this->assertStringNotContainsString($newToken, $live->fresh()->getRawOriginal('credentials'));
        $this->assertSame($sandbox->credentials, $sandbox->fresh()->credentials);
        $this->assertDatabaseHas('audit_events', ['event' => 'commerce.bakong.token_renewed']);
    }

    public function test_unverified_replacement_leaves_saved_token_and_limits_retries(): void
    {
        $this->admin();
        $live = $this->gateway(true, now()->subMinute()->timestamp);
        Http::fake([
            self::PRODUCTION.'/v1/renew_token' => Http::response(['responseCode' => 0, 'data' => [
                'token' => $this->token(now()->addDays(90)->timestamp),
            ]]),
            self::PRODUCTION.'/v1/check_bakong_account' => Http::response(['responseCode' => 1, 'errorCode' => 6]),
        ]);

        $this->artisan('commerce:renew-bakong-token')->expectsOutput('Bakong Production · renewal failed')->assertFailed();
        $this->artisan('commerce:renew-bakong-token')->expectsOutput('Bakong Production · retry waiting')->assertSuccessful();

        Http::assertSentCount(2);
        $this->assertSame($live->credentials, $live->fresh()->credentials);
        $this->assertSame('Bakong Production renewal needs attention', AccountNotification::query()->sole()->title);
        $this->assertDatabaseMissing('audit_events', ['event' => 'commerce.bakong.token_renewed']);
    }

    public function test_early_test_command_targets_sandbox_only_and_keeps_a_concurrent_manual_change(): void
    {
        $live = $this->gateway(true, now()->subMinute()->timestamp);
        $sandbox = $this->gateway(false, now()->addDays(2)->timestamp);
        $manual = array_replace($sandbox->credentials, ['token' => $this->token(now()->addDays(88)->timestamp)]);
        Http::fake([
            self::SANDBOX.'/v1/renew_token' => Http::response(['responseCode' => 0, 'data' => [
                'token' => $this->token(now()->addDays(90)->timestamp),
            ]]),
            self::SANDBOX.'/v1/check_bakong_account' => function () use ($sandbox, $manual) {
                $sandbox->credentials = $manual;
                $sandbox->save();

                return Http::response(['responseCode' => 0]);
            },
        ]);

        $this->artisan('commerce:renew-bakong-token --sandbox-early')
            ->expectsOutput('Bakong Sandbox · credentials changed during renewal')->assertFailed();

        Http::assertSentCount(2);
        $this->assertSame($manual, $sandbox->fresh()->credentials);
        $this->assertSame($live->credentials, $live->fresh()->credentials);
    }

    public function test_admin_can_save_or_clear_environment_email_without_rotating_the_token(): void
    {
        $live = $this->gateway(true, now()->addDays(3)->timestamp);
        $sandbox = $this->gateway(false, now()->addDays(10)->timestamp, null);
        $method = CommercePaymentMethod::query()->where('provider', 'bakong')->firstOrFail();
        $admin = $this->admin();
        $settings = route('dashboard.payment-methods.index');
        $save = route('dashboard.payment-methods.update', $method);
        $sandboxToken = $this->token(now()->addDays(11)->timestamp);

        $this->actingAs($admin)->from($settings)->patch($save, [
            'available' => false, 'bakong_renewal' => ['sandbox' => 'sandbox@example.test', 'live' => ''],
            'bakong_environment' => 'sandbox', 'activate_bakong_environment' => '0',
            'gateway' => ['sandbox' => ['token' => $sandboxToken]],
        ])->assertRedirect($settings)->assertSessionHasNoErrors();

        $this->get($settings)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Automatic token renewal')->assertSee('sandbox@example.test')
            ->assertDontSee($live->credentials['token'])->assertDontSee($sandbox->credentials['token']);
        $this->assertSame('sandbox@example.test', $sandbox->fresh()->credentials['renewal_email']);
        $this->assertSame($sandboxToken, $sandbox->fresh()->credentials['token']);
        $this->assertNull($live->fresh()->credentials['renewal_email']);
        $this->assertSame($live->credentials['token'], $live->fresh()->credentials['token']);
        $this->assertTrue(CommerceGatewaySetting::checkoutIsLive('bakong'));

        $this->from($settings)->patch($save, ['available' => false, 'bakong_renewal' => ['live' => 'not an email']])
            ->assertSessionHasErrors('bakong_renewal.live')->assertSessionMissing('_old_input.bakong_renewal');
    }

    public function test_sandbox_does_not_request_renewal_with_the_production_token(): void
    {
        $this->admin();
        $expiry = now()->addDays(2)->timestamp;
        $live = $this->gateway(true, $expiry);
        $sandbox = $this->gateway(false, $expiry);
        Http::fake();

        $this->artisan('commerce:renew-bakong-token --sandbox-early')
            ->expectsOutput('Bakong Sandbox · sandbox shares production token')->assertFailed();

        Http::assertNothingSent();
        $this->assertSame($sandbox->credentials, $sandbox->fresh()->credentials);
        $this->assertSame($live->credentials, $live->fresh()->credentials);
        $this->assertSame('Bakong Sandbox token shared with Production', AccountNotification::query()->sole()->title);
    }

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Renewal administrator', 'email' => 'renewal-admin@example.test',
            'password' => 'TestOnlyPassword123!', 'role' => 'super_admin',
        ]);
    }

    private function gateway(bool $live, int $expiry, ?string $email = 'merchant@example.test'): CommerceGatewaySetting
    {
        $gateway = CommerceGatewaySetting::forMode('bakong', $live)->firstOrFail();
        $gateway->credentials = [
            'account_id' => 'merchant@test', 'merchant_name' => 'Test recipient', 'merchant_city' => 'Phnom Penh',
            'token' => $this->token($expiry), 'renewal_email' => $email,
        ];
        $gateway->save();

        return $gateway;
    }

    private function token(int $expiry): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode(['exp' => $expiry], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return 'eyJhbGciOiJIUzI1NiJ9.'.$payload.'.test';
    }
}
