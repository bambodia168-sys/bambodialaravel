<?php

namespace App\Http\Controllers;

use App\Models\AccountNotification;
use App\Models\AuditEvent;
use App\Models\MarketplaceOrder;
use App\Models\User;
use App\Support\CommerceGateway;
use App\Support\ProductLicenseLifecycle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class StripeRefundController extends Controller
{
    public function store(Request $request, string $number, CommerceGateway $gateway): RedirectResponse
    {
        abort_unless(config('commerce.enabled') && Schema::hasTable('commerce_order_operations'), 404);
        $data = $request->validate([
            'reference' => ['required', 'regex:/\Are_[A-Za-z0-9_]{1,110}\z/'],
            'access' => ['required', 'in:retain,revoke'],
            'note' => ['required', 'string', 'max:500'],
        ]);
        $orders = MarketplaceOrder::query()->where('number', $number)->whereNotNull('license_tier')
            ->whereIn('status', ['completed', 'refunded'])->whereHas('paidAttempt', fn ($payment) => $payment->where('provider', 'stripe'));
        $order = (clone $orders)->firstOrFail();
        $payment = $order->paidAttempt()->firstOrFail();
        $gateway->confirmFullStripeRefund($payment, $data['reference']);

        $actor = $request->user();
        try {
            DB::transaction(function () use ($orders, $payment, $data, $actor, $request): void {
                $order = $orders->lockForUpdate()->firstOrFail();
                $current = $order->paidAttempt()->lockForUpdate()->firstOrFail();
                abort_unless($current->is($payment) && $current->settlement_reference === $payment->settlement_reference
                    && $current->live_mode === $payment->live_mode && $order->total === $payment->order->total
                    && $order->currency === $payment->order->currency, 409, 'The order payment changed during verification.');

                $record = $order->operations()->first();
                if ($order->status === 'refunded') {
                    abort_unless($record?->refund_reference === $data['reference']
                        && $record->refund_access === $data['access'], 409, 'A refund decision is already recorded for this order.');

                    return;
                }

                $order->operations()->updateOrCreate([], [
                    'commerce_payment_id' => $current->id,
                    'refund_status' => 'refunded',
                    'refund_reference' => $data['reference'],
                    'refund_resolved_at' => now(),
                    'refund_note' => $data['note'],
                    'refund_access' => $data['access'],
                    'refund_confirmed_by' => $actor->id,
                ]);
                $order->update(['status' => 'refunded']);
                if ($data['access'] === 'revoke') {
                    $this->revokeOrderAccess($order, $actor);
                }

                AccountNotification::deliver((int) $order->buyer_id, 'commerce.refund.confirmed', 'Refund confirmed',
                    'Stripe confirmed a full refund for this order. Open your order to see the access decision.',
                    route('account.orders.show', $order->number, false));
                AuditEvent::record('commerce.order.refund_confirmed', $actor, $request);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'This refund reference is already linked to an order.');
        }

        return redirect()->route('dashboard.orders.show', $number)->with('status', 'Stripe refund confirmed and recorded.');
    }

    private function revokeOrderAccess(MarketplaceOrder $order, User $actor): void
    {
        $grant = $order->entitlement()->lockForUpdate()->first();
        if (! $grant) {
            return;
        }
        abort_unless((int) $grant->user_id === (int) $order->buyer_id
            && (int) $grant->product_file_version_id === (int) $order->fulfillment_version_id,
            409, 'The existing download entitlement does not match this order.');

        $reason = 'Confirmed full Stripe refund for order '.$order->number.'.';
        if ($grant->status !== 'revoked') {
            $grant->update([
                'status' => 'revoked', 'revoked_at' => now(), 'revoked_by' => $actor->id, 'revocation_reason' => $reason,
            ]);
            $grant->actions()->create(['actor_id' => $actor->id, 'action' => 'revoked', 'reason' => $reason, 'created_at' => now()]);
        }
        $license = $grant->license()->first();
        if ($license && $license->status !== 'revoked') {
            ProductLicenseLifecycle::status($license, 'revoke', $actor, $reason);
        }
    }
}
