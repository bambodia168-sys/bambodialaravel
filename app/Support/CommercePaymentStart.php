<?php

namespace App\Support;

use App\Models\CommerceGatewaySetting;
use App\Models\CommercePayment;
use App\Models\CommercePaymentMethod;
use App\Models\MarketplaceOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CommercePaymentStart
{
    public function __construct(private readonly CommerceQrGateway $qrGateway) {}

    public function prepare(MarketplaceOrder $order, int $methodId, bool $requireSelectedQr = false): CommercePayment
    {
        abort_unless(CommerceCollection::enabled(), 404);
        $payment = DB::transaction(function () use ($order, $methodId, $requireSelectedQr): CommercePayment {
            $locked = MarketplaceOrder::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless($locked->status === 'awaiting_payment' && $locked->total !== '0.00'
                && $locked->currency === 'USD', 409);
            $active = $locked->payments()->whereIn('status', CommercePayment::ACTIVE)->first();
            if ($active) {
                abort_unless($active->commerce_payment_method_id === $methodId
                    && (! $requireSelectedQr || ($active->dynamicQr() && $locked->payment_method === $active->method_name))
                    && in_array($active->status, ['starting', 'checkout_open'], true), 409, 'An existing payment is still open.');

                return $active;
            }
            $method = CommercePaymentMethod::query()->where('available', true)->lockForUpdate()->findOrFail($methodId);
            abort_if($requireSelectedQr && (! $method->dynamicQr() || $locked->payment_method !== $method->name), 409);
            abort_unless($method->usesGateway(), 422, 'This gateway is unavailable.');
            $live = CommerceGatewaySetting::checkoutIsLive($method->provider);
            CommerceGatewaySetting::forMode($method->provider, $live)->lockForUpdate()->firstOrFail();
            abort_unless($method->availableForCheckout(), 422, 'This gateway is unavailable.');
            OrderFulfillment::ensureReady($locked);

            return $locked->payments()->create([
                'number'=>(string) Str::uuid(), 'commerce_payment_method_id'=>$method->id,
                'method_name'=>$method->name, 'provider'=>$method->provider, 'live_mode'=>$live, 'status'=>'starting',
            ]);
        }, 3);
        $live = CommerceGatewaySetting::checkoutIsLive($payment->provider);
        abort_unless($payment->live_mode === $live && CommerceGateway::ready($payment->provider, $live)
            && ($live || ! app()->environment('production')), 503);
        if ($payment->dynamicQr() && $payment->remote_id === null) {
            // Serialize generation so concurrent clicks never open two bank bills.
            DB::transaction(function () use ($payment): void {
                $locked = CommercePayment::query()->lockForUpdate()->findOrFail($payment->id);
                if ($locked->status === 'starting' && $locked->remote_id === null) {
                    abort_if($locked->provider === 'aba' && $locked->created_at->lt(now()->subMinutes(5)),
                        409, 'This QR payment needs settlement review.');
                    $locked->update($this->qrGateway->issue($locked) + ['status' => 'checkout_open']);
                }
            }, 1);
        }

        return $payment->refresh();
    }
}
