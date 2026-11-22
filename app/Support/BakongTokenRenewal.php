<?php

namespace App\Support;

use App\Models\AccountNotification;
use App\Models\AuditEvent;
use App\Models\CommerceGatewaySetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class BakongTokenRenewal
{
    public static function expiresAt(?string $token): ?CarbonImmutable
    {
        if ($token === null || strlen($token) > 2048
            || ! preg_match('/^[A-Za-z0-9_-]+\.([A-Za-z0-9_-]+)\.[A-Za-z0-9_-]+$/D', $token, $parts)) {
            return null;
        }

        // A JWT claim is only a scheduling hint. Bakong authenticates the candidate before it is stored.
        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $expiry = $payload === false ? null : (json_decode($payload, true)['exp'] ?? null);

        return is_int($expiry) && $expiry > 0 && $expiry <= 253402300799
            ? CarbonImmutable::createFromTimestampUTC($expiry) : null;
    }

    public function run(bool $live, bool $sandboxEarly = false): string
    {
        if ($live && $sandboxEarly) {
            throw new \InvalidArgumentException('Early renewal is available only for Bakong Sandbox.');
        }

        $mode = $live ? 'live' : 'sandbox';

        return Cache::lock('bakong-renewal:'.$mode, 45)
            ->get(fn () => $this->renew($live, $mode, $sandboxEarly)) ?: 'busy';
    }

    private function renew(bool $live, string $mode, bool $sandboxEarly): string
    {
        $gateway = CommerceGatewaySetting::forMode('bakong', $live)->first();
        $saved = $gateway?->credentials ?? [];
        $credentials = CommerceGatewaySetting::effective('bakong', $live);
        if (! CommerceGatewaySetting::configured('bakong', $live, $credentials)) {
            return 'not configured';
        }

        $email = $saved['renewal_email'] ?? null;
        $expiry = self::expiresAt($credentials['token']);
        $now = now();
        $monitored = filled($email) || CommerceGatewaySetting::checkoutIsLive('bakong') === $live;

        if (! $expiry) {
            if (filled($email)) {
                $this->alert($mode, 'expiry unavailable', 'Replace the saved Bakong API token with one that has a readable expiry.');
            }

            return 'expiry unavailable';
        }

        $expired = $expiry->lte($now);
        if (! $expired && ! $sandboxEarly) {
            if ($monitored && $expiry->lte($now->copy()->addDays(3))) {
                $this->alert($mode, 'token expiring', 'The Bakong API token expires '.$expiry->toIso8601String()
                    .'. Automatic renewal runs after expiry when a registered email is saved.');
            }

            return 'not due';
        }

        if (! filled($email) || ! $gateway) {
            if ($expired && $monitored) {
                $this->alert($mode, 'token expired', 'Renew the Bakong API token manually or save the registered email in Payment methods.');
            }

            return 'automatic renewal off';
        }

        // Each environment gets at most one attempt per hour while Bakong is unavailable.
        if (! Cache::add('bakong-renewal:retry:'.$mode, true, $now->copy()->addHour())) {
            return 'retry waiting';
        }

        try {
            $candidate = $this->issueAndVerify($live, $email, $credentials['account_id'], $expiry);
        } catch (ConnectionException) {
            $candidate = null;
        }

        if ($candidate === null) {
            $this->alert($mode, 'renewal needs attention', 'Bakong did not provide a verified replacement token. Check Payment methods and renew manually if needed.');

            return 'renewal failed';
        }

        return DB::transaction(function () use ($gateway, $saved, $candidate): string {
            $current = CommerceGatewaySetting::query()->lockForUpdate()->find($gateway->id);
            if (! $current || ($current->credentials ?? []) !== $saved) {
                return 'credentials changed during renewal';
            }

            $current->credentials = array_replace($saved, ['token' => $candidate]);
            $current->save();
            AuditEvent::record('commerce.bakong.token_renewed');

            return 'renewed';
        }, 3);
    }

    private function issueAndVerify(bool $live, string $email, string $accountId, CarbonImmutable $expiry): ?string
    {
        $client = Http::baseUrl(CommerceQrGateway::bakongBaseUrl($live))->acceptJson()->asJson()
            ->connectTimeout(4)->timeout(8)->withoutRedirecting();
        $response = $client->post('/v1/renew_token', ['email' => $email]);
        if (! $response->successful() || ! in_array($response->json('responseCode'), [0, '0'], true)) {
            return null;
        }

        $token = $response->json('data.token');
        $newExpiry = is_string($token) ? self::expiresAt($token) : null;
        if (! $newExpiry || $newExpiry->lte(now()) || $newExpiry->lte($expiry)) {
            return null;
        }

        $check = $client->withToken($token)->post('/v1/check_bakong_account', ['accountId' => $accountId]);

        return $check->successful() && in_array($check->json('responseCode'), [0, '0'], true) ? $token : null;
    }

    private function alert(string $mode, string $status, string $message): void
    {
        $dedupe = 'bakong-renewal:alert:'.$mode.':'.str_replace(' ', '-', $status);
        if (! Cache::add($dedupe, true, now()->addDay())) {
            return;
        }

        $label = $mode === 'live' ? 'Production' : 'Sandbox';
        $path = route('dashboard.payment-methods.index', absolute: false);
        try {
            DB::transaction(function () use ($label, $status, $message, $path): void {
                foreach (User::query()->where('role', 'super_admin')->where('account_status', 'active')->pluck('id') as $admin) {
                    AccountNotification::deliver((int) $admin, 'commerce.bakong.token_alert', 'Bakong '.$label.' '.$status, $message, $path);
                }
            }, 3);
        } catch (\Throwable $error) {
            Cache::forget($dedupe);
            throw $error;
        }
    }
}
