<?php

namespace App\Support;

use App\Models\CommerceCart;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CheckoutCart
{
    public const SESSION = 'commerce.guest_cart';
    public const INTENT = 'commerce.checkout_intent';
    public const LIMIT = 25;

    public static function items(Request $request): array
    {
        if ($user = $request->user()) {
            return CommerceCart::query()->find($user->id)?->items ?? [];
        }

        return $request->session()->get(self::SESSION, []);
    }

    public static function change(Request $request, callable $change): void
    {
        if ($user = $request->user()) {
            DB::transaction(function () use ($user, $change): void {
                User::query()->lockForUpdate()->findOrFail($user->id);
                $cart = CommerceCart::query()->firstOrNew(['user_id' => $user->id]);
                $cart->items = $change($cart->items ?? []);
                $cart->save();
            }, 3);

            return;
        }

        $request->session()->put(self::SESSION, $change(self::items($request)));
    }

    public static function attach(array $guestItems, User $user): void
    {
        if ($guestItems === []) {
            return;
        }

        DB::transaction(function () use ($guestItems, $user): void {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $cart = CommerceCart::query()->firstOrNew(['user_id' => $user->id]);
            $accountItems = $cart->items ?? [];
            // Keep existing selections for duplicates and retain the guest's new items.
            $cart->items = array_slice($accountItems + $guestItems, 0, self::LIMIT, true);
            $cart->save();
        }, 3);
    }

    public static function afterLogin(Request $request, User $user): RedirectResponse
    {
        if (config('commerce.enabled')) {
            self::attach($request->session()->get(self::SESSION, []), $user);
            $request->session()->forget(self::SESSION);
            if ($request->session()->pull(self::INTENT) && ! $request->session()->has('url.intended')) {
                return redirect()->route('checkout');
            }
        }

        return redirect()->intended($user->defaultWorkspaceUrl());
    }
}
