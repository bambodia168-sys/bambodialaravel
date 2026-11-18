<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Support\Security\Totp;
use App\Support\SystemSecurity;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use App\Support\CheckoutCart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class TwoFactorController extends Controller
{
    private const DEVICE_LIMIT = 5;

    public function settings(Request $request): Response
    {
        $enrollmentAllowed = SystemSecurity::enabled('two_factor_enrollment');
        $credential = UserTwoFactor::query()->with('devices')->find($request->user()->id);
        $enrollment = $enrollmentAllowed ? $this->enrollment($request) : null;
        $enrollment = $enrollment && $enrollment['existing'] === $credential?->authenticatorKey() ? $enrollment : null;
        $uri = $enrollment ? Totp::uri(
            (string) SiteSetting::setting('identity.name'),
            $request->user()->email,
            $enrollment['secret'],
            $enrollment['name'],
        ) : null;
        $qr = $uri ? 'data:image/svg+xml;base64,'.base64_encode(
            (new Writer(new ImageRenderer(new RendererStyle(256, 4), new SvgImageBackEnd)))->writeString($uri)
        ) : null;

        return response()->view('account.security', [
            'credential' => $credential,
            'enrollment' => $enrollment,
            'enrollmentAllowed' => $enrollmentAllowed,
            'enrollmentQr' => $qr,
            'enrollmentUri' => $uri,
            'deviceLimit' => self::DEVICE_LIMIT,
            'recoveryCodes' => $request->session()->pull('twofactor.recovery_codes', []),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function begin(Request $request): RedirectResponse
    {
        abort_unless(SystemSecurity::enabled('two_factor_enrollment'), 403);
        $values = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'name' => ['nullable', 'string', 'max:80'],
            'current_code' => ['nullable', 'string', 'max:40'],
        ]);
        $user = $request->user();
        $existing = DB::transaction(function () use ($request, $user, $values): ?string {
            $credential = UserTwoFactor::query()->whereKey($user->id)->lockForUpdate()->first();
            if ($credential) {
                abort_if($credential->devices()->count() >= self::DEVICE_LIMIT - 1, 409, 'Remove an authenticator to add another.');
                if (! self::consume($credential, $values['current_code'] ?? '')) {
                    throw ValidationException::withMessages(['current_code' => 'Enter a valid code from an existing authenticator or an unused recovery code.']);
                }
            }
            AuditEvent::record('auth.two_factor.enrollment.started', $user, $request);

            return $credential?->authenticatorKey();
        });

        $request->session()->put([
            'twofactor.enroll_secret' => Totp::createSecret(),
            'twofactor.enroll_at' => now()->timestamp,
            'twofactor.enroll_user' => $user->id,
            'twofactor.enroll_existing' => $existing,
            'twofactor.enroll_name' => $values['name'] ?? 'Authenticator',
        ]);

        return redirect()->to(route('account.security').'#account-two-factor');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->clearEnrollment($request);

        return redirect()->to(route('account.security').'#account-two-factor')->with('authenticator_status', 'Authenticator setup canceled.');
    }

    public function enable(Request $request): RedirectResponse
    {
        abort_unless(SystemSecurity::enabled('two_factor_enrollment'), 403);
        $values = $request->validate(['code' => ['required', 'regex:/\A[0-9]{6}\z/D']]);
        $enrollment = $this->enrollment($request);
        if (! $enrollment) {
            throw ValidationException::withMessages(['code' => 'Your enrollment session expired. Start again.']);
        }

        if (Totp::matchingCounter($enrollment['secret'], $values['code']) === null) {
            throw ValidationException::withMessages(['code' => 'Invalid authenticator code.']);
        }

        $user = $request->user();
        $recovery = DB::transaction(function () use ($user, $request, $enrollment): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $credential = UserTwoFactor::query()->whereKey($user->id)->lockForUpdate()->first();
            abort_unless($enrollment['existing'] === $credential?->authenticatorKey(), 409, 'Your authenticators changed. Start setup again.');
            if ($credential) {
                abort_if($credential->devices()->count() >= self::DEVICE_LIMIT - 1, 409, 'Remove an authenticator to add another.');
                $credential->devices()->create(['name' => $enrollment['name'], 'secret' => $enrollment['secret']]);
                AuditEvent::record('auth.two_factor.authenticator.added', $user, $request);

                return [];
            }

            $recovery = Totp::recoveryCodes();
            UserTwoFactor::query()->create([
                'user_id' => $user->id,
                'secret' => $enrollment['secret'],
                'authenticator_key' => (string) Str::uuid(),
                'authenticator_name' => $enrollment['name'],
                'recovery_hashes' => array_map(fn ($code) => hash('sha256', $code), $recovery),
                'enabled_at' => now(),
            ]);
            AuditEvent::record('auth.two_factor.enabled', $user, $request);

            return $recovery;
        });
        $this->clearEnrollment($request);
        if ($recovery) {
            $request->session()->put('twofactor.recovery_codes', $recovery);
        }

        return redirect()->route('account.security')->with('authenticator_status', $recovery
            ? 'Two-factor authentication enabled. Save your one-use recovery codes now.'
            : 'Authenticator added.');
    }

    public function removeDevice(Request $request, string $device): RedirectResponse
    {
        $values = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'code' => ['required', 'string', 'max:40'],
        ]);

        DB::transaction(function () use ($request, $values, $device): void {
            $credential = UserTwoFactor::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $primary = $device === $credential->authenticatorKey();
            $removed = $primary ? $credential->devices()->oldest()->first() : $credential->devices()->find($device);
            abort_unless($removed, $primary ? 409 : 404, $primary ? 'Keep at least one authenticator or disable two-factor authentication.' : '');
            if (! self::consume($credential, $values['code'])) {
                throw ValidationException::withMessages(['code' => 'Invalid code or already-used recovery code.']);
            }
            if ($primary) {
                $removed->refresh();
                $credential->update([
                    'secret' => $removed->secret,
                    'authenticator_key' => $removed->id,
                    'authenticator_name' => $removed->name,
                    'last_counter' => $removed->last_counter,
                ]);
            }
            $removed->delete();
            AuditEvent::record('auth.two_factor.authenticator.removed', $request->user(), $request);
        });

        return redirect()->to(route('account.security').'#account-two-factor')->with('authenticator_status', 'Authenticator removed.');
    }

    private function enrollment(Request $request): ?array
    {
        $secret = $request->session()->get('twofactor.enroll_secret');
        $at = (int) $request->session()->get('twofactor.enroll_at');
        if (! is_string($secret) || $request->session()->get('twofactor.enroll_user') !== $request->user()->id
            || $at > now()->timestamp || $at < now()->subMinutes(10)->timestamp) {
            return null;
        }

        return [
            'secret' => $secret,
            'name' => $request->session()->get('twofactor.enroll_name', 'Authenticator'),
            'existing' => $request->session()->get('twofactor.enroll_existing'),
        ];
    }

    private function clearEnrollment(Request $request): void
    {
        $request->session()->forget([
            'twofactor.enroll_secret', 'twofactor.enroll_at', 'twofactor.enroll_user', 'twofactor.enroll_existing', 'twofactor.enroll_name',
        ]);
    }

    public function disable(Request $request): RedirectResponse
    {
        $values=$request->validate([
            'current_password'=>['required','string'],
            'code'=>['required','string','max:40'],
        ]);
        $user=$request->user();
        if (! Hash::check($values['current_password'],$user->password)) {
            throw ValidationException::withMessages(['current_password'=>'Current password is incorrect.']);
        }
        DB::transaction(function () use ($user,$request,$values): void {
            $credential=UserTwoFactor::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! self::consume($credential,$values['code'])) {
                throw ValidationException::withMessages(['code'=>'Invalid code or already-used recovery code.']);
            }
            $credential->delete();
            AuditEvent::record('auth.two_factor.disabled',$user,$request);
        });
        $this->clearEnrollment($request);

        return redirect()->to(route('account.security').'#account-two-factor')->with('authenticator_status', 'Two-factor authentication disabled.');
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        if (! $this->pendingId($request)) {
            return redirect()->route('login');
        }
        return view('auth.two-factor-challenge');
    }

    public function verifyChallenge(Request $request): RedirectResponse
    {
        $values=$request->validate(['code'=>['required','string','max:40']]);
        $id=$this->pendingId($request);
        if (! $id) {
            return redirect()->route('login')->withErrors(['code'=>'Sign-in session expired. Try again.']);
        }
        $user=User::query()->findOrFail($id);
        abort_unless($user->account_status==='active',403,'This account is not active.');

        $accepted=DB::transaction(function () use ($request,$user,$values): bool {
            $credential=UserTwoFactor::query()->whereKey($user->id)->lockForUpdate()->first();
            return $credential && self::consume($credential,$values['code']);
        });
        if (! $accepted) {
            AuditEvent::record('auth.two_factor.challenge.failed',null,$request);
            throw ValidationException::withMessages(['code'=>'Invalid or previously used verification code.']);
        }

        $remember=(bool)$request->session()->get('twofactor.pending_remember',false);
        $request->session()->forget([
            'twofactor.pending_id','twofactor.pending_at','twofactor.pending_remember',
        ]);
        Auth::login($user,$remember);
        $request->session()->regenerate();
        AuditEvent::record('auth.two_factor.challenge.passed',$user,$request);
        AuditEvent::record('auth.login.success',$user,$request);

        return CheckoutCart::afterLogin($request, $user);
    }

    public static function consume(UserTwoFactor $credential, string $code): bool
    {
        foreach ([$credential, ...$credential->devices()->get()] as $device) {
            $counter = Totp::matchingCounter($device->secret, trim($code), $device->last_counter);
            if ($counter !== null) {
                $device->last_counter = $counter;
                $device->save();

                return true;
            }
        }
        $hash=hash('sha256',strtoupper(preg_replace('/[^A-Z0-9]/i','',$code)));
        $codes=$credential->recovery_hashes??[];
        foreach ($codes as $index=>$storedHash) {
            if (hash_equals($storedHash,$hash)) {
                unset($codes[$index]);
                $credential->recovery_hashes=array_values($codes);
                $credential->save();
                return true;
            }
        }
        return false;
    }

    private function pendingId(Request $request): ?int
    {
        $id=$request->session()->get('twofactor.pending_id');
        $at=(int)$request->session()->get('twofactor.pending_at',0);
        if (! is_numeric($id) || $at<=0 || now()->timestamp-$at>300) {
            $request->session()->forget([
                'twofactor.pending_id','twofactor.pending_at','twofactor.pending_remember',
            ]);
            return null;
        }
        return (int)$id;
    }
}
