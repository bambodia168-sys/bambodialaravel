<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CommerceCartController;
use App\Support\CheckoutCart;
use App\Support\EmailOutbox;
use App\Support\Security\SignIn;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Models\AuditEvent;
use App\Models\FeatureFlag;
use App\Support\Security\TurnstileVerifier;
use App\Models\PolicyAcknowledgement;
use App\Models\PolicyPublication;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function login(): View
    {
        return view('auth.login');
    }

    public function authenticate(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        TurnstileVerifier::enforce($request,'login');
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            AuditEvent::record('auth.login.failed', null, $request);
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        if (UserTwoFactor::query()->whereKey($request->user()->id)->exists()) {
            return SignIn::challenge($request, $request->user(), $request->boolean('remember'));
        }
        $request->session()->regenerate();
        AuditEvent::record('auth.login.success', $request->user(), $request);
        SignIn::notify($request, $request->user());

        return CheckoutCart::afterLogin($request, $request->user());
    }

    public function register(): View
    {
        abort_unless(FeatureFlag::isEnabled('registration'), 404);
        return view('auth.register', ['termsPublication' => PolicyPublication::currentRegistrationTerms()]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(FeatureFlag::isEnabled('registration'), 404);
        $terms = PolicyPublication::currentRegistrationTerms();
        if ($terms === null) {
            throw ValidationException::withMessages([
                'terms_accepted' => 'Registration is unavailable until the administrator publishes Terms of Service.',
            ]);
        }
        $values = $request->validate([
            'terms_accepted' => ['required', 'accepted'],
            'terms_publication_id' => ['required','integer',Rule::in([(int) $terms->id])],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:254', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
        ]);

        TurnstileVerifier::enforce($request,'register');

        // The exact immutable publication accepted during registration is stored
        // in the existing policy receipt ledger, not just a mutable boolean.
        $guestItems = config('commerce.enabled') ? $request->session()->get(CheckoutCart::SESSION, []) : [];
        $user = DB::transaction(function () use ($values, $terms, $request, $guestItems): User {
            $user = User::query()->create([
                'name' => $values['name'],
                'email' => Str::lower($values['email']),
                'password' => $values['password'],
                'role' => 'customer',
            ]);
            PolicyAcknowledgement::query()->create([
                'policy_publication_id' => $terms->id,
                'user_id' => $user->id,
                'sha256' => $terms->sha256,
                'record_type' => 'registration_acceptance',
                'created_at' => now(),
            ]);
            AuditEvent::record('auth.registration.terms_accepted', $user, $request);
            CheckoutCart::attach($guestItems, $user);
            EmailOutbox::queue('account.welcome', $user->id, 'registration', [], route('account', absolute: false));

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        if ($guestItems !== []) {
            $request->session()->forget([CheckoutCart::SESSION, CheckoutCart::INTENT]);
            return CommerceCartController::verificationEmail($user);
        }

        return redirect($user->defaultWorkspaceUrl());
    }

    public function logout(Request $request): RedirectResponse
    {
        AuditEvent::record('auth.logout', $request->user(), $request);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    public function forgotPassword(): View
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        TurnstileVerifier::enforce($request,'reset');
        $status = Password::sendResetLink($request->only('email'));

        return back()->with('status', $status === Password::RESET_LINK_SENT
            ? __($status)
            : __('If the account exists, a password reset email will be sent.'));
    }

    public function resetPassword(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $values = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
        ]);

        $status = Password::reset($values, function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();
            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', __($status))
            : back()->withErrors(['email' => __($status)]);
    }
}
