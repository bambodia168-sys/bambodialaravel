<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\FeatureFlag;
use App\Models\PolicyPublication;
use App\Models\SocialLoginIdentity;
use App\Models\SocialLoginProvider;
use App\Models\User;
use App\Support\Security\SignIn;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Throwable;

final class SocialAuthController extends Controller
{
    public function start(Request $request, string $provider): RedirectResponse
    {
        return $this->redirectToProvider($request, $provider);
    }

    public function connect(Request $request, string $provider): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);

        return $this->redirectToProvider($request, $provider, $request->user()->id);
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $attempt = $request->session()->pull('social_login.attempt');
        $target = $request->user() ? 'account.security' : 'login';
        if (! is_array($attempt) || $attempt['provider'] !== $provider
            || $attempt['user'] !== $request->user()?->id || $attempt['at'] < now()->subMinutes(5)->timestamp) {
            return redirect()->route($target)->withErrors(['social_auth' => 'Sign-in request expired. Start again.']);
        }

        $driver = $this->driver($provider);
        try {
            $profile = $driver->user();
            $sub = $profile->getId();
        } catch (Throwable) {
            return redirect()->route($target)->withErrors(['social_auth' => 'The provider could not verify your sign-in. Try again.']);
        }
        abort_unless((is_string($sub) || is_int($sub)) && (string) $sub !== '', 422);
        $subject = hash('sha256', (string) $sub);

        if ($request->user()) {
            return $this->link($request, $provider, $subject);
        }

        $identity = SocialLoginIdentity::query()->with('user')
            ->where('provider', $provider)->where('subject_hash', $subject)->first();
        if ($identity) {
            if (! $identity->user || $identity->user->account_status !== 'active') {
                return redirect()->route('login')->withErrors([
                    'social_auth' => 'This Bambodia account is unavailable. Contact support if you need help.',
                ]);
            }

            return SignIn::external($request, $identity->user, $provider);
        }

        if ($provider === 'linkedin') {
            return SocialLinkedInController::begin($request, $profile, $subject);
        }

        // Google is authoritative for verified @gmail.com / @googlemail.com
        // addresses. Recover existing accounts from before explicit social
        // identities were introduced, but never trust an unverified email or
        // use an email match to relink a different Google subject.
        if ($provider === 'google') {
            $verifiedEmail = $this->verifiedGoogleEmail($profile);
            if ($verifiedEmail !== null) {
                $user = $this->recoverVerifiedGoogleAccount($request, $profile, $subject);
                if ($user) {
                    return SignIn::external($request, $user, $provider);
                }

                // A Google identity with no Bambodia account is a new
                // registration, not an account-recovery operation. Require
                // explicit acceptance of the current registration terms.
                $existing = User::query()->whereRaw('LOWER(email) = ?', [$verifiedEmail])->exists();
                if (! $existing && FeatureFlag::isEnabled('registration')
                    && PolicyPublication::currentRegistrationTerms() !== null) {
                    $name = trim((string) $profile->getName());
                    $request->session()->put('social_google.registration', [
                        'email' => $verifiedEmail,
                        'name' => $name !== '' ? mb_substr($name, 0, 120) : 'Bambodia Member',
                        'subject_hash' => $subject,
                        'at' => now()->timestamp,
                    ]);

                    return redirect()->route('social.google.registration');
                }
            }
        }

        return redirect()->route('login')->withErrors([
            'social_auth' => 'Sign in with your password and link this provider from Account Security first.',
        ]);
    }

    public function disconnect(Request $request, string $provider): RedirectResponse
    {
        abort_unless(isset(SocialLoginProvider::PROVIDERS[$provider]), 404);
        $request->validate(['current_password' => ['required', 'current_password']]);
        SocialLoginIdentity::query()->where('user_id', $request->user()->id)->where('provider', $provider)->firstOrFail()->delete();
        AuditEvent::record('auth.social.disconnected', $request->user(), $request);

        return back()->with('status', SocialLoginProvider::PROVIDERS[$provider][0].' disconnected.');
    }

    /**
     * Restore Google sign-in for an existing account when its older Google
     * identity was never persisted. Restrict this recovery to authoritative
     * Google-managed Gmail domains; other addresses require explicit linking.
     */
    private function recoverVerifiedGoogleAccount(Request $request, SocialiteUser $profile, string $subject): ?User
    {
        $email = $this->verifiedGoogleEmail($profile);
        if ($email === null || ! preg_match('/\\A[^@\\s]+@(?:gmail|googlemail)\\.com\\z/D', $email)) {
            return null;
        }

        try {
            return DB::transaction(function () use ($request, $email, $subject): ?User {
                $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
                if (! $user || $user->account_status !== 'active') {
                    return null;
                }

                // Never replace an existing binding, even if the submitted
                // Gmail address matches this account.
                if (SocialLoginIdentity::query()->where('user_id', $user->id)
                    ->where('provider', 'google')->exists()) {
                    return null;
                }

                SocialLoginIdentity::query()->create([
                    'user_id' => $user->id,
                    'provider' => 'google',
                    'subject_hash' => $subject,
                ]);
                AuditEvent::record('auth.social.connected', $user, $request);

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // Another request linked the account before this transaction.
            // A fresh sign-in can use its established immutable identity.
            return null;
        }
    }

    /**
     * Google explicitly verified this email. This alone does not authorize
     * attaching an existing Bambodia account on custom domains.
     */
    private function verifiedGoogleEmail(SocialiteUser $profile): ?string
    {
        $email = $profile->getEmail();
        $raw = $profile->user;

        if (! is_string($email) || ! is_array($raw)
            || ($raw['email_verified'] ?? false) !== true
            || ! is_string($raw['email'] ?? null)
            || strtolower(trim($raw['email'])) !== strtolower(trim($email))) {
            return null;
        }

        $email = strtolower(trim($email));

        return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL)
            ? $email : null;
    }

    private function redirectToProvider(Request $request, string $provider, ?int $userId = null): RedirectResponse
    {
        $driver = $this->driver($provider);
        $request->session()->forget(['social_google.registration', SocialLinkedInController::SESSION_KEY]);
        $request->session()->put('social_login.attempt', ['provider' => $provider, 'user' => $userId, 'at' => now()->timestamp]);

        return $driver->redirect();
    }

    private function driver(string $provider): AbstractProvider
    {
        abort_unless(isset(SocialLoginProvider::PROVIDERS[$provider]), 404);
        $setting = SocialLoginProvider::query()->where('enabled', true)->findOrFail($provider);

        return Socialite::buildProvider(SocialLoginProvider::PROVIDERS[$provider][1], [
            'client_id' => $setting->client_id,
            'client_secret' => $setting->client_secret,
            'redirect' => SocialLoginProvider::callbackUrl($provider),
        ]);
    }

    private function link(Request $request, string $provider, string $subject): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $provider, $subject): void {
                $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
                abort_unless($user->account_status === 'active', 403);
                SocialLoginIdentity::query()->firstOrCreate([
                    'user_id' => $user->id, 'provider' => $provider, 'subject_hash' => $subject,
                ]);
                AuditEvent::record('auth.social.connected', $user, $request);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return redirect()->route('account.security')->withErrors([
                'social_auth' => 'This provider is already linked. Disconnect it before changing accounts.',
            ]);
        }

        return redirect()->route('account.security')->with('status', SocialLoginProvider::PROVIDERS[$provider][0].' connected.');
    }
}
