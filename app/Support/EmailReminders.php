<?php

namespace App\Support;

use App\Models\CommerceCart;
use App\Models\EmailDelivery;
use App\Models\MarketplaceOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class EmailReminders
{
    public static function queueDue(): void
    {
        $now = now();
        $today = $now->copy()->setTimezone('Asia/Phnom_Penh');
        $memberIds = EmailOutbox::subscribers()->select('id');
        $before = $now->copy()->subDay();
        $after = $before->copy()->subDay();

        if (config('commerce.enabled') && EmailOutbox::template('commerce.abandoned_cart')['enabled']) {
            CommerceCart::query()->whereIn('user_id', $memberIds)->whereBetween('updated_at', [$after, $before])
                ->chunkById(100, function ($carts): void {
                    foreach ($carts as $cart) {
                        if ($cart->items) {
                            $snapshot = $cart->updated_at->toDateTimeString();
                            EmailOutbox::queue('commerce.abandoned_cart', $cart->user_id, $snapshot,
                                ['snapshot' => $snapshot], route('cart', absolute: false));
                        }
                    }
                }, 'user_id');
        }

        if (config('commerce.enabled') && EmailOutbox::template('commerce.unpaid_order')['enabled']) {
            self::unpaidOrders()->whereIn('buyer_id', $memberIds)->whereBetween('created_at', [$after, $before])
                ->chunkById(100, function ($orders): void {
                    foreach ($orders as $order) {
                        EmailOutbox::queue('commerce.unpaid_order', $order->buyer_id, $order->number, [
                            'source_id' => $order->id, 'order_number' => $order->reference(), 'title' => $order->listing_title,
                            'amount' => $order->currency.' '.$order->total, 'payment_method' => $order->payment_method,
                        ], route('account.orders.show', $order->reference(), false));
                    }
                });
        }

        if (EmailOutbox::template('account.incomplete_profile')['enabled']) {
            self::withoutPhoto(EmailOutbox::subscribers())
                ->whereBetween('created_at', [$now->copy()->subDays(8), $now->copy()->subWeek()])
                ->chunkById(100, function ($members): void {
                    foreach ($members as $member) {
                        EmailOutbox::queue('account.incomplete_profile', $member->id, 'profile-setup', [],
                            route('account.profile.edit', absolute: false));
                    }
                });
        }

        $days = [(int) $today->day];
        if (! $today->isLeapYear() && $today->format('m-d') === '02-28') {
            $days[] = 29;
        }

        if (EmailOutbox::template('account.birthday')['enabled']) {
            EmailOutbox::subscribers()->whereHas('userProfile', function ($profile) use ($today, $days): void {
                $profile->whereMonth('date_of_birth', $today->month)
                    ->where(fn ($matching) => $matching->whereDay('date_of_birth', $days[0])
                        ->when(count($days) > 1, fn ($matching) => $matching->orWhereDay('date_of_birth', 29)));
            })->chunkById(100, function ($members) use ($today): void {
                foreach ($members as $member) {
                    EmailOutbox::queue('account.birthday', $member->id, $today->format('Y'), [],
                        route('home', absolute: false));
                }
            });
        }

        if (EmailOutbox::template('account.anniversary')['enabled']) {
            $utcPreviousDay = $today->copy()->startOfDay()->utc();
            $startOfYear = $today->copy()->startOfYear()->utc();
            EmailOutbox::subscribers()->where('created_at', '<', $startOfYear)
                ->where(function ($dates) use ($today, $days, $utcPreviousDay): void {
                    foreach ([$utcPreviousDay, $today] as $date) {
                        $dates->orWhere(fn ($match) => $match->whereMonth('created_at', $date->month)
                            ->whereDay('created_at', $date->day));
                    }
                    if (count($days) > 1) {
                        $dates->orWhere(fn ($match) => $match->whereMonth('created_at', 2)->whereDay('created_at', 29));
                    }
                })->chunkById(100, function ($members) use ($today, $days): void {
                    foreach ($members as $member) {
                        $joined = $member->created_at->copy()->setTimezone($today->timezone);
                        $years = $today->year - $joined->year;
                        if ($years > 0 && $today->month === $joined->month && in_array((int) $joined->day, $days, true)) {
                            EmailOutbox::queue('account.anniversary', $member->id, $today->format('Y'),
                                ['years' => $years.($years === 1 ? ' year' : ' years')], route('account', absolute: false));
                        }
                    }
                });
        }
    }

    public static function stillApplies(EmailDelivery $delivery, User $recipient): bool
    {
        if (! $recipient->hasVerifiedEmail() || ! $recipient->marketing_subscribed_at
            || $recipient->marketing_subscribed_at->gt($delivery->created_at)
            || $delivery->created_at->lt(now()->subDays(2))) {
            return false;
        }

        return match ($delivery->event) {
            'commerce.abandoned_cart' => config('commerce.enabled')
                && self::cartUnchanged($recipient->id, $delivery->context['snapshot'] ?? null),
            'commerce.unpaid_order' => config('commerce.enabled') && self::unpaidOrders()
                ->whereKey($delivery->context['source_id'] ?? 0)->where('buyer_id', $recipient->id)->exists(),
            'account.incomplete_profile' => self::withoutPhoto(User::query())->whereKey($recipient->id)->exists(),
            default => true,
        };
    }

    private static function cartUnchanged(int $userId, ?string $snapshot): bool
    {
        if ($snapshot === null) {
            return false;
        }
        $cart = CommerceCart::query()->find($userId);

        return $cart && $cart->items && $cart->updated_at->toDateTimeString() === $snapshot;
    }

    private static function unpaidOrders(): Builder
    {
        return MarketplaceOrder::query()->where('status', 'awaiting_payment')->where('total', '>', 0)
            ->whereDoesntHave('payments', fn ($payment) => $payment->whereIn('status', ['paid', 'manual_review']));
    }

    private static function withoutPhoto(Builder $members): Builder
    {
        return $members->whereDoesntHave('userProfile', fn ($profile) => $profile
            ->whereNotNull('avatar_path')->where('avatar_path', '!=', ''));
    }
}
