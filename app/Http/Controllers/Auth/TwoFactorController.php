<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Support\Security\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class TwoFactorController extends Controller
{
    public function settings(Request $request): View
    {
        return view('account.security',[
            'credential'=>UserTwoFactor::query()->find($request->user()->id),
            'enrollmentSecret'=>$request->session()->get('twofactor.enroll_secret'),
            'recoveryCodes'=>$request->session()->pull('twofactor.recovery_codes',[]),
        ]);
    }

    public function begin(Request $request): RedirectResponse
    {
        $values=$request->validate(['current_password'=>['required','string']]);
        $user=$request->user();
        if (! Hash::check($values['current_password'],$user->password)) {
            throw ValidationException::withMessages(['current_password'=>'Current password is incorrect.']);
        }
        abort_if(UserTwoFactor::query()->whereKey($user->id)->exists(),409,'Two-factor authentication is already active.');

        $request->session()->put([
            'twofactor.enroll_secret'=>Totp::createSecret(),
            'twofactor.enroll_at'=>now()->timestamp,
        ]);
        AuditEvent::record('auth.two_factor.enrollment.started',$user,$request);
        return back()->with('status','Enter the secret in your authenticator app and verify its six-digit code.');
    }

    public function enable(Request $request): RedirectResponse
    {
        $values=$request->validate(['code'=>['required','regex:/\A[0-9]{6}\z/D']]);
        $secret=$request->session()->get('twofactor.enroll_secret');
        $timestamp=(int)$request->session()->get('twofactor.enroll_at',0);
        abort_unless(
            is_string($secret) && now()->timestamp-$timestamp<=600 && $timestamp>0,
            422,'Your enrollment session expired. Start again.'
        );

        $counter=Totp::matchingCounter($secret,$values['code']);
        if ($counter===null) {
            throw ValidationException::withMessages(['code'=>'Invalid authenticator code.']);
        }
        $user=$request->user();
        $recovery=Totp::recoveryCodes();
        DB::transaction(function () use ($user,$request,$secret,$counter,$recovery): void {
            abort_if(UserTwoFactor::query()->whereKey($user->id)->lockForUpdate()->exists(),409,
                'Two-factor authentication is already active.');
            UserTwoFactor::query()->create([
                'user_id'=>$user->id,
                'secret'=>$secret,
                'recovery_hashes'=>array_map(fn($code)=>hash('sha256',$code),$recovery),
                'last_counter'=>null, // Enrollment verifies possession but must not consume the first login code.
                'enabled_at'=>now(),
            ]);
            AuditEvent::record('auth.two_factor.enabled',$user,$request);
        });
        $request->session()->forget(['twofactor.enroll_secret','twofactor.enroll_at']);
        $request->session()->put('twofactor.recovery_codes',$recovery);
        return redirect()->route('account.security')->with('status',
            'Two-factor authentication enabled. Save your one-use recovery codes now.');
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
        return back()->with('status','Two-factor authentication disabled.');
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

        return redirect()->intended($user->isAdministrator()
            ? route('dashboard') : url('/account').'?='.$user->public_id);
    }

    public static function consume(UserTwoFactor $credential, string $code): bool
    {
        $counter=Totp::matchingCounter($credential->secret,trim($code),$credential->last_counter);
        if ($counter!==null) {
            $credential->last_counter=$counter;
            $credential->save();
            return true;
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
