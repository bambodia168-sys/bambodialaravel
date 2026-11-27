<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AccountNotification;
use App\Models\User;
use App\Notifications\CheckoutVerification;
use App\Support\CheckoutCart;
use App\Support\EmailOutbox;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Throwable;

class EmailVerificationController extends Controller
{
    public const CHECKOUT_RETURN = 'account.email.checkout_return';

    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('verification.verified');
        }

        return response()->view('auth.verify-email', [
            'continueCheckout' => self::canContinueCheckout($request),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function send(Request $request): RedirectResponse
    {
        return self::sendFor($request->user());
    }

    public static function sendFor(User $user): RedirectResponse
    {
        abort_unless($user->account_status === 'active', 403);
        if ($user->hasVerifiedEmail()) {
            return redirect()->route('verification.verified');
        }
        $response = redirect()->route('verification.notice');
        if (app()->environment('production') && ! EmailOutbox::deliveryReady()) {
            return $response->withErrors(['email' => 'Verification email delivery is temporarily unavailable. Please try again later.']);
        }

        try {
            $user->notify(new CheckoutVerification);
        } catch (Throwable $exception) {
            // Do not log the signed verification URL or mail credentials.
            \Illuminate\Support\Facades\Log::warning('Account verification mail could not be sent.', ['type' => $exception::class]);

            return $response->withErrors(['email' => 'The verification email could not be sent. Please try again shortly.']);
        }

        AccountNotification::deliver(
            $user->id,
            'account.email.verification_sent',
            'Verification email sent',
            'Open the verification link sent to your account email address to confirm your email.',
            route('verification.notice', absolute: false),
        );

        return $response->with('status', 'Verification email sent.');
    }

    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = $request->user();
        abort_unless(hash_equals((string) $user->getKey(), $id)
            && hash_equals(sha1($user->getEmailForVerification()), $hash), 403);
        DB::transaction(function () use ($user, $hash) {
            $member = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($member->account_status === 'active'
                && hash_equals(sha1($member->getEmailForVerification()), $hash), 403);
            if (! $member->hasVerifiedEmail() && $member->markEmailAsVerified()) {
                event(new Verified($member));
                AccountNotification::deliver(
                    $member->id,
                    'account.email.verified',
                    'Your email verified',
                    'Your account email address is confirmed.',
                    route('verification.verified', absolute: false),
                );
            }
        });

        return redirect()->route('verification.verified');
    }

    public function verified(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return response()->view('auth.email-verified', [
            'continueCheckout' => self::canContinueCheckout($request),
        ])->header('Cache-Control', 'private, no-store');
    }

    public static function requireForCheckout(Request $request): RedirectResponse
    {
        $request->session()->put(self::CHECKOUT_RETURN, $request->user()->id);

        return redirect()->route('verification.notice');
    }

    private static function canContinueCheckout(Request $request): bool
    {
        return config('commerce.enabled')
            && (int) $request->session()->get(self::CHECKOUT_RETURN) === (int) $request->user()->id
            && CheckoutCart::items($request) !== [];
    }
}
