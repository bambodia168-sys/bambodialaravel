<?php

namespace App\Support;

use App\Models\CommerceOrderOperation;
use App\Models\MarketplaceOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

final class CommerceSettlementReport
{
    public const QUEUES = [
        'unmatched' => 'Awaiting statement match',
        'refund_requested' => 'Refund requested',
        'payout_pending' => 'Payout pending',
        'paid_out' => 'Paid out',
        'recovery_due' => 'Recovery due',
        'recovered' => 'Recovered',
    ];

    public static function orders(array $filters, ?int $sellerId): Builder
    {
        $orders = MarketplaceOrder::query()->whereIn('status', ['completed', 'refunded'])
            ->whereHas('payments', static fn (Builder $payment) => $payment->where('status', 'paid')
                ->whereNotNull('settlement_reference')
                ->when(app()->isProduction(), static fn (Builder $live) => $live->where('live_mode', true)));
        if ($sellerId !== null) {
            $orders->where('seller_id', $sellerId);
        }
        if (isset($filters['order'])) {
            $orders->where('number', $filters['order']);
        }
        if (isset($filters['from'])) {
            $orders->where('completed_at', '>=', $filters['from'].' 00:00:00');
        }
        if (isset($filters['to'])) {
            $orders->where('completed_at', '<=', $filters['to'].' 23:59:59');
        }

        $queue = $filters['queue'] ?? null;
        if (! Schema::hasTable((new CommerceOrderOperation)->getTable())) {
            return $orders->when($queue !== null && $queue !== 'unmatched',
                static fn (Builder $orders) => $orders->whereRaw('1 = 0'));
        }

        $orders->with('operations');

        return match ($queue) {
            'unmatched' => $orders->whereDoesntHave('operations', fn (Builder $record) => $record->whereNotNull('reconciled_at')),
            'refund_requested' => $orders->whereHas('operations', fn (Builder $record) => $record->where('refund_status', 'requested')),
            'payout_pending' => $orders->where('status', 'completed')->whereHas(
                'operations', fn (Builder $record) => $record->whereNotNull('reconciled_at')
                    ->where('seller_payable', '>', 0)->whereNull('paid_out_at')
                    ->where(fn (Builder $refund) => $refund->whereNull('refund_status')->orWhere('refund_status', 'declined')),
            ),
            'paid_out' => $orders->where('status', 'completed')->whereHas('operations', fn (Builder $record) => $record->whereNotNull('paid_out_at')),
            'recovery_due' => $orders->whereHas('operations', fn (Builder $record) => $record
                ->where('refund_status', 'refunded')->whereNotNull('paid_out_at')->whereNull('recovered_at')),
            'recovered' => $orders->whereHas('operations', fn (Builder $record) => $record->whereNotNull('recovered_at')),
            default => $orders,
        };
    }

    public static function payoutStatus(MarketplaceOrder $order): string
    {
        $record = self::record($order);

        return match (true) {
            (bool) $record?->recoveryDue() => 'Recovery due',
            $record?->recovered_at !== null => 'Recovered',
            $record?->paid_out_at !== null => 'Paid out',
            $order->status === 'refunded' => 'Cancelled after refund',
            $record?->refund_status === 'requested' => 'On hold',
            $record?->seller_payable === '0.00' => 'Nothing payable',
            $record?->seller_payable !== null => 'Payout pending',
            default => 'Awaiting reconciliation',
        };
    }

    public static function record(MarketplaceOrder $order): ?CommerceOrderOperation
    {
        return $order->relationLoaded('operations') ? $order->getRelation('operations') : null;
    }

    public static function writeCsv($stream, Builder $orders): void
    {
        fputcsv($stream, [
            'Order number', 'Listing', 'Payment verified (UTC)', 'Currency', 'Amount collected',
            'Statement status', 'Refund status', 'Payout status', 'Seller payable', 'Paid out on (UTC)', 'Recovered on (UTC)',
        ], ',', '"', '', "\r\n");

        foreach ($orders->lazyByIdDesc(500) as $order) {
            $record = self::record($order);
            $title = $order->listing_title;
            if (preg_match('/^(?:[\t\r\n]|[\p{Z}\p{Cc}\x{FEFF}]*[=+\-@＝＋－＠])/u', $title)) {
                $title = "'".$title;
            }
            fputcsv($stream, [
                $order->number, $title, $order->completed_at?->toDateTimeString(), $order->currency, $order->total,
                $record?->reconciled_at ? 'Matched' : 'Awaiting match', $record?->refund_status ?? '',
                self::payoutStatus($order), $order->status === 'refunded' ? '0.00' : $record?->seller_payable,
                $record?->paid_out_at?->toDateTimeString(),
                $record?->recovered_at?->toDateTimeString(),
            ], ',', '"', '', "\r\n");
        }
    }
}
