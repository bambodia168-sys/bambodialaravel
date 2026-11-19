<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceOrder;
use App\Support\CommerceSettlementReport;
use App\Support\OrderFulfillment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CommerceSettlementController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $creator = $request->routeIs('studio.commerce-operations', 'localized.*.studio.commerce-operations');
        $orders = CommerceSettlementReport::orders($filters, $creator ? $request->user()->id : null)
            ->latest('id')->paginate(15)->appends($filters);

        $pendingReceipts = $creator ? null : MarketplaceOrder::query()
            ->where('status', 'awaiting_payment')
            ->withWhereHas('payments', fn ($payments) => $payments->where('status', 'manual_review')
                ->where('live_mode', true)->whereNotNull('proof_path')->latest('id'))
            ->when(isset($filters['order']), fn ($query) => $query->where('number', $filters['order']))
            ->latest('id')->paginate(10, pageName: 'receipts')->appends($filters)->fragment('pending-receipts');

        $pendingDeliveries = $creator ? null : MarketplaceOrder::query()->deliveryPending()->with(['entitlement', 'paidAttempt'])
            ->when(isset($filters['order']), fn ($query) => $query->where('number', $filters['order']))
            ->when(isset($filters['from']), fn ($query) => $query->where('completed_at', '>=', $filters['from'].' 00:00:00'))
            ->when(isset($filters['to']), fn ($query) => $query->where('completed_at', '<=', $filters['to'].' 23:59:59'))
            ->oldest('completed_at')->oldest('id')
            ->paginate(10, pageName: 'deliveries')->appends($filters)->fragment('pending-deliveries')
            ->through(function (MarketplaceOrder $order): array {
                $blocker = $order->entitlement ? OrderFulfillment::entitlementBlocker($order, $order->entitlement) : null;

                return ['order' => $order, 'blocker' => $blocker ?? OrderFulfillment::readinessBlocker($order)];
            });

        return response()->view('commerce.operations.index', compact('creator', 'filters', 'orders', 'pendingReceipts', 'pendingDeliveries'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function statement(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $creator = $request->routeIs('studio.commerce-operations.statement', 'localized.*.studio.commerce-operations.statement');
        $orders = CommerceSettlementReport::orders($filters, $creator ? $request->user()->id : null);

        return response()->streamDownload(static function () use ($orders): void {
            $stream = fopen('php://output', 'wb');
            try {
                CommerceSettlementReport::writeCsv($stream, $orders);
            } finally {
                fclose($stream);
            }
        }, $creator ? 'bambodia-sales-settlements.csv' : 'bambodia-commerce-settlements.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function filters(Request $request): array
    {
        abort_unless(config('commerce.enabled'), 404);

        return array_filter($request->validate([
            'order' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), ['after_or_equal:from'])],
            'queue' => ['nullable', Rule::in(array_keys(CommerceSettlementReport::QUEUES))],
        ]), static fn ($value) => $value !== null);
    }
}
