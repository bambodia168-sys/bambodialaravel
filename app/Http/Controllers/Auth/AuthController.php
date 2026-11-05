<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\AuditEvent;
use App\Models\FeatureFlag;
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

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            AuditEvent::record('auth.login.failed', null, $request);
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate();
        AuditEvent::record('auth.login.success', $request->user(), $request);

        return redirect()->intended($this->destination($request->user()));
    }

    public function register(): View
    {
        abort_unless(FeatureFlag::isEnabled('registration'), 404);
        return view('auth.register', ['termsPublication' => $this->currentTerms()]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(FeatureFlag::isEnabled('registration'), 404);
        $terms = $this->currentTerms();
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

        // The exact immutable publication accepted during registration is stored
        // in the existing policy receipt ledger, not just a mutable boolean.
        $user = DB::transaction(function () use ($values, $terms, $request): User {
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

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect($this->destination($user));
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

    private function currentTerms(): ?PolicyPublication
    {
        return PolicyPublication::query()
            ->with('policy')
            ->whereHas('policy', fn ($query) => $query->where('status','published')
                ->where('category','terms'))
            ->latest('id')
            ->first();
    }

    private function destination(User $user): string
    {
        return $user->isAdministrator()
            ? route('dashboard')
            : url('/account').'?='.$user->public_id;
    }
}
