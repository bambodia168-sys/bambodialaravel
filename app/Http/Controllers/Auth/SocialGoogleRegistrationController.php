<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\FeatureFlag;
use App\Models\PolicyAcknowledgement;
use App\Models\PolicyPublication;
use App\Models\SocialLoginIdentity;
use App\Models\User;
use App\Support\EmailOutbox;
use App\Support\Security\SignIn;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class SocialGoogleRegistrationController extends Controller
{
    private const SESSION_KEY = 'social_google.registration';

    public function show(Request $request): View|RedirectResponse
    {
        abort_unless(FeatureFlag::isEnabled('registration'), 404);

        $pending = $this->pending($request);
        if ($pending === null) {
            return redirect()->route('login')->withErrors([
                'social_auth' => 'Your Google registration expired. Sign in with Google again.',
            ]);
        }

        $termsPublication = PolicyPublication::currentRegistrationTerms();
        if ($termsPublication === null) {
            return redirect()->route('login')->withErrors([
                'social_auth' => 'Registration is unavailable until the Terms of Service are published.',
            ]);
        }

        return view('auth.social-google-registration', compact('pending', 'termsPublication'));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(FeatureFlag::isEnabled('registration'), 404);

        $pending = $this->pending($request);
        if ($pending === null) {
            return redirect()->route('login')->withErrors([
                'social_auth' => 'Your Google registration expired. Sign in with Google again.',
            ]);
        }

        $terms = PolicyPublication::currentRegistrationTerms();
        if ($terms === null) {
            return redirect()->route('login')->withErrors([
                'social_auth' => 'Registration is unavailable until the Terms of Service are published.',
            ]);
        }

        $request->validate([
            'terms_accepted' => ['required', 'accepted'],
            'terms_publication_id' => ['required', 'integer', Rule::in([(int) $terms->id])],
        ]);

        try {
            $user = DB::transaction(function () use ($request, $pending, $terms): ?User {
                // Never merge with or overwrite a previously registered user.
                // An existing account must use its own authentication method.
                if (User::query()->whereRaw('LOWER(email) = ?', [$pending['email']])
                    ->lockForUpdate()->exists()
                    || SocialLoginIdentity::query()->where('provider', 'google')
                        ->where('subject_hash', $pending['subject_hash'])->exists()) {
                    return null;
                }

                $user = User::query()->create([
                    'name' => $pending['name'],
                    'email' => $pending['email'],
                    'password' => Str::random(64), // unknown, hashed by the User cast
                    'role' => 'customer',
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();

                SocialLoginIdentity::query()->create([
                    'user_id' => $user->id,
                    'provider' => 'google',
                    'subject_hash' => $pending['subject_hash'],
                ]);
                PolicyAcknowledgement::query()->create([
                    'policy_publication_id' => $terms->id,
                    'user_id' => $user->id,
                    'sha256' => $terms->sha256,
                    'record_type' => 'registration_acceptance',
                    'created_at' => now(),
                ]);

                AuditEvent::record('auth.registration.terms_accepted', $user, $request);
                AuditEvent::record('auth.social.connected', $user, $request);
                EmailOutbox::queue('account.welcome', $user->id, 'google-registration', [],
                    route('account', absolute: false));

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $user = null;
        }

        $request->session()->forget(self::SESSION_KEY);
        if ($user === null) {
            return redirect()->route('login')->withErrors([
                'social_auth' => 'A Bambodia account already exists. Sign in to that account and connect Google in Account Security.',
            ]);
        }

        return SignIn::external($request, $user, 'google');
    }

    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending)
            || ! is_string($pending['email'] ?? null)
            || ! filter_var($pending['email'], FILTER_VALIDATE_EMAIL)
            || ! is_string($pending['name'] ?? null)
            || ! is_string($pending['subject_hash'] ?? null)
            || ! preg_match('/\\A[a-f0-9]{64}\\z/D', $pending['subject_hash'])
            || ! is_int($pending['at'] ?? null)
            || $pending['at'] > now()->timestamp
            || $pending['at'] < now()->subMinutes(10)->timestamp) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return $pending;
    }
}
