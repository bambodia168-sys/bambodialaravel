<?php

namespace App\Http\Controllers;

use App\Models\CommercePaymentMethod;
use App\Models\MarketplaceOrder;
use App\Support\BusinessToolDirectory;
use App\Support\MarketplaceReadinessAudit;
use App\Support\UserManagementAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BusinessToolsController extends Controller
{
    private const PERIODS = [7, 30, 90];

    public function index(Request $request): Response
    {
        [$creator, $canViewOrders] = $this->access($request);
        $tools = BusinessToolDirectory::tools($request->user(), $creator, $canViewOrders);
        $periods = self::PERIODS;
        $days = $canViewOrders ? $this->days($request) : 30;
        $counts = $totals = null;
        if ($canViewOrders) {
            $orders = $this->orders($request, $creator, $days);
            $counts = (clone $orders)->selectRaw('status, COUNT(*) AS quantity')->groupBy('status')->pluck('quantity', 'status');
            $totals = $orders->where('status', 'completed')->selectRaw('currency, SUM(total) AS amount')
                ->groupBy('currency')->orderBy('currency')->pluck('amount', 'currency');
        }

        $readiness = null;
        if (! $creator && $request->user()->role === 'super_admin') {
            $audit = MarketplaceReadinessAudit::inspect();
            $readiness = [
                'listings' => $audit['preview_markers']['public_listings'],
                'jobs' => $audit['preview_markers']['published_jobs'],
                'articles' => $audit['counts']['published_knowledge_articles'],
                'paymentSubmission' => config('commerce.enabled') && config('commerce.payments_enabled'),
                'enabledMethods' => config('commerce.enabled')
                    ? CommercePaymentMethod::query()->where('available', true)->count() : 0,
                'mailer' => config('mail.default'),
            ];
        }

        $workspace = $creator ? 'creator' : 'admin';
        $route = ($creator ? 'studio' : 'dashboard').'.business-tools';

        return response()->view('business-tools.index', compact(
            'workspace', 'route', 'tools', 'canViewOrders', 'counts', 'totals', 'readiness', 'periods', 'days',
        ))->header('Cache-Control', 'private, no-store');
    }

    public function open(Request $request): RedirectResponse
    {
        [$creator, $canViewOrders] = $this->access($request);
        $data = $request->validate(['tool' => ['required', 'string', 'max:100']]);
        foreach (BusinessToolDirectory::tools($request->user(), $creator, $canViewOrders) as $group) {
            if (isset($group[$data['tool']])) {
                $route = explode(':', $data['tool'], 2)[0];

                return redirect()->route($route, $group[$data['tool']][2] ?? []);
            }
        }

        abort(404);
    }

    public function export(Request $request): StreamedResponse
    {
        [$creator, $canViewOrders] = $this->access($request);
        abort_unless($canViewOrders, 403);

        $days = $this->days($request);
        $orders = $this->orders($request, $creator, $days)->select([
            'id', 'number', 'listing_title', 'created_at', 'status', 'currency', 'total', 'completed_at',
        ]);

        return response()->streamDownload(function () use ($orders): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Order', 'Listing', 'Placed at', 'Status', 'Currency', 'Total', 'Completed at'], escape: '');

            foreach ($orders->lazyById(500) as $order) {
                $title = $this->csvText($order->listing_title);
                fputcsv($stream, [
                    $order->number,
                    $title,
                    $order->created_at->toIso8601String(),
                    MarketplaceOrder::STATUSES[$order->status] ?? $this->csvText($order->status),
                    $this->csvText($order->currency),
                    $order->total,
                    $order->completed_at?->toIso8601String(),
                ], escape: '');
            }

            fclose($stream);
        }, "orders-last-{$days}-days.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function access(Request $request): array
    {
        $creator = $request->routeIs('studio.business-tools.*', 'localized.*.studio.business-tools.*');
        $canViewOrders = config('commerce.enabled') && ($creator
            || UserManagementAccess::allows($request->user(), 'commerce_orders'));

        return [$creator, $canViewOrders];
    }

    private function days(Request $request): int
    {
        $data = $request->validate(['days' => ['sometimes', 'integer', Rule::in(self::PERIODS)]]);

        return (int) ($data['days'] ?? 30);
    }

    private function orders(Request $request, bool $creator, int $days): Builder
    {
        return MarketplaceOrder::query()
            ->when($creator, fn (Builder $orders) => $orders->where('seller_id', $request->user()->id))
            ->whereBetween('created_at', [today()->subDays($days - 1), now()]);
    }

    private function csvText(string $value): string
    {
        return preg_match('/^[\s\x{FEFF}]*[=+\-@]|^[\t\r\n]/u', $value) ? "'".$value : $value;
    }
}
