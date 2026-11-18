<?php

namespace App\Support;

use App\Models\AccountNotification;
use App\Models\CommerceOrderOperation;
use App\Models\MarketplaceOrder;
use App\Models\ProductEntitlementAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class CommerceOperations
{
    public const LABELS = [
        'request_refund' => 'Request refund',
        'reject_refund' => 'Decline refund request',
        'record_refund' => 'Record completed refund',
        'reconcile' => 'Match payment statement',
    ];

    public static function enabled(): bool
    {
        return config('commerce.enabled') && config('commerce.operations_enabled')
            && Schema::hasTable('commerce_order_operations');
    }

    public static function orders(): Builder
    {
        return MarketplaceOrder::query()
            ->whereIn('status', ['completed', 'refunded'])
            ->where('total', '>', 0)
            ->whereHas('paidAttempt');
    }

    public static function actions(MarketplaceOrder $order, User $actor, bool $buyer): array
    {
        if ($buyer) {
            return (int) $order->buyer_id === $actor->id && $actor->hasVerifiedEmail()
                && $order->status === 'completed' && $order->operations?->refund_status === null
                ? ['request_refund'] : [];
        }
        if ($actor->role !== 'super_admin'
            || in_array($actor->id, [(int) $order->buyer_id, (int) $order->seller_id], true)) {
            return [];
        }
        $record = $order->operations;
        $actions = $order->status === 'completed' ? ['record_refund'] : [];
        if ($record?->refund_status === 'requested') {
            $actions[] = 'reject_refund';
        }
        if ($record?->reconciled_at === null) {
            $actions[] = 'reconcile';
        }

        return $actions;
    }

    public static function record(string $number, User $actor, string $action, array $data, bool $buyer): void
    {
        try {
            DB::transaction(function () use ($number, $actor, $action, $data, $buyer): void {
                $order = self::orders()->where('number', $number)->when($buyer,
                    fn (Builder $orders) => $orders->where('buyer_id', $actor->id))->lockForUpdate()->firstOrFail();
                abort_unless(in_array($action, self::actions($order, $actor, $buyer), true), 409,
                    'This action is no longer available for the order.');
                $payment = $order->paidAttempt()->firstOrFail();
                $record = $order->operations()->firstOrCreate([], ['commerce_payment_id' => $payment->id]);
                $reference = isset($data['reference']) ? strtoupper($data['reference']) : null;
                $note = $data['note'] ?? null;

                switch ($action) {
                    case 'request_refund':
                        $record->update(['refund_status' => 'requested', 'refund_reason' => $note, 'refund_requested_at' => now()]);
                        break;
                    case 'reject_refund':
                        $record->update(['refund_status' => 'declined', 'refund_note' => $note, 'refund_resolved_at' => now()]);
                        break;
                    case 'record_refund':
                        self::sameAmount($data['amount'], $order->total);
                        $record->update([
                            'refund_status' => 'refunded', 'refund_note' => $note,
                            'refund_reference' => $reference, 'refund_resolved_at' => now(),
                        ]);
                        $order->update(['status' => 'refunded']);
                        self::revokeDelivery($order, $actor);
                        break;
                    case 'reconcile':
                        self::sameAmount($data['amount'], $order->total);
                        $references = explode(':', $payment->settlement_reference, 2);
                        abort_unless(hash_equals($payment->settlement_reference, $data['payment_reference'])
                            || hash_equals(end($references), $data['payment_reference']), 422,
                            'The payment reference must match the verified payment.');
                        abort_if(self::cents($data['seller_payable']) > self::cents($order->total), 422,
                            'Seller payable cannot exceed the amount collected.');
                        $record->update([
                            'statement_reference' => $reference, 'seller_payable' => $data['seller_payable'], 'reconciled_at' => now(),
                        ]);
                        break;
                }

                $record->events()->create([
                    'actor_id' => $actor->id, 'action' => $action, 'note' => $note, 'created_at' => now(),
                ]);
                if (in_array($action, ['record_refund', 'reject_refund'], true)) {
                    AccountNotification::deliver((int) $order->buyer_id, 'commerce.refund.updated', 'Refund status updated',
                        $action === 'record_refund' ? 'A full refund was recorded for your order.' : 'Your refund request was declined.',
                        '/account/orders/'.$order->number);
                }
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['reference' => 'This external reference is already in use.']);
        }
    }

    private static function revokeDelivery(MarketplaceOrder $order, User $actor): void
    {
        $grant = $order->entitlement()->lockForUpdate()->first();
        if (! $grant) {
            return;
        }
        $reason = 'Full refund recorded for order '.$order->number;
        if ($grant->status === 'active') {
            $grant->update([
                'status' => 'revoked', 'revoked_at' => now(), 'revoked_by' => $actor->id, 'revocation_reason' => $reason,
            ]);
            ProductEntitlementAction::query()->create([
                'product_entitlement_id' => $grant->id, 'actor_id' => $actor->id, 'action' => 'revoked',
                'reason' => $reason, 'created_at' => now(),
            ]);
        }
        $license = $grant->license()->lockForUpdate()->first();
        if ($license && $license->status !== 'revoked') {
            ProductLicenseLifecycle::status($license, 'revoke', $actor, $reason);
        }
    }

    private static function sameAmount(string $entered, string $expected): void
    {
        abort_unless(self::cents($entered) === self::cents($expected), 422,
            'The amount must match the expected amount in the order currency.');
    }

    private static function cents(string $amount): int
    {
        return (int) str_replace('.', '', $amount);
    }
}
