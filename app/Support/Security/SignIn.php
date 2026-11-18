<?php

namespace App\Support\Security;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Support\CheckoutCart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class SignIn
{
    public static function external(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->account_status === 'active', 403);
        if (UserTwoFactor::query()->whereKey($user->id)->exists()) {
            return self::challenge($request, $user);
        }

        Auth::login($user);
        $request->session()->regenerate();
        AuditEvent::record('auth.login.success', $user, $request);

        return CheckoutCart::afterLogin($request, $user);
    }

    public static function challenge(Request $request, User $user, bool $remember = false): RedirectResponse
    {
        $intended = $request->session()->get('url.intended');
        $checkout = $request->session()->only([CheckoutCart::SESSION, CheckoutCart::INTENT]);
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
