<?php

namespace App\Support\Security;

use App\Models\AuditEvent;
use App\Models\SocialLoginProvider;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Support\CheckoutCart;
use App\Support\EmailOutbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class SignIn
{
    public static function external(Request $request, User $user, ?string $provider = null): RedirectResponse
    {
        abort_unless($user->account_status === 'active', 403);
        if (UserTwoFactor::query()->whereKey($user->id)->exists()) {
            $response = self::challenge($request, $user);
            $request->session()->put('twofactor.pending_social_provider', $provider);

            return $response;
        }

        Auth::login($user);
        $request->session()->regenerate();
        AuditEvent::record('auth.login.success', $user, $request);
        self::socialAlert($request, $user, $provider);

        return CheckoutCart::afterLogin($request, $user);
    }

    public static function socialAlert(Request $request, User $user, ?string $provider): void
    {
        if ($provider === null || ! isset(SocialLoginProvider::PROVIDERS[$provider])) {
            return;
        }

        $label = SocialLoginProvider::PROVIDERS[$provider][0];
        EmailOutbox::queue('account.social_sign_in', $user->id, 'sign-in-'.$request->session()->getId(), [
            'title' => 'New '.$label.' sign-in',
            'message' => 'A successful sign-in to your account used '.$label.'.',
        ], route('account.security', absolute: false));
    }

    public static function challenge(Request $request, User $user, bool $remember = false): RedirectResponse
    {
        $intended = $request->session()->get('url.intended');
        $checkout = array_filter([
            CheckoutCart::SESSION => $request->session()->get(CheckoutCart::SESSION),
            CheckoutCart::INTENT => $request->session()->get(CheckoutCart::INTENT),
        ], fn ($value) => $value !== null);
        if (Auth::check()) {
            Auth::logout();
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put([
            'twofactor.pending_id' => $user->id,
            'twofactor.pending_at' => now()->timestamp,
            'twofactor.pending_remember' => $remember,
        ] + $checkout);
        if (is_string($intended)) {
            $request->session()->put('url.intended', $intended);
        }

        return redirect()->route('login.two-factor');
    }
}
