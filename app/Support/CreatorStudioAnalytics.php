<?php

namespace App\Support;

use App\Models\FreelanceContract;
use App\Models\JobProposal;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\ServiceInquiry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CreatorStudioAnalytics
{
    public const PERIODS = ['month', 'quarter', 'year'];
    public const TYPES = ['all', 'product', 'project', 'freelance', 'service'];

    public function report(int $creatorId, string $period = 'month', string $type = 'all'): array
    {
        $period = in_array($period, self::PERIODS, true) ? $period : 'month';
        $type = in_array($type, self::TYPES, true) ? $type : 'all';
        $now = CarbonImmutable::now();
        $start = match ($period) {
            'year' => $now->startOfYear(),
            'quarter' => $now->startOfQuarter(),
            default => $now->startOfMonth(),
        };
        $end = $now->endOfDay();
        [$labels, $bucketCount] = $this->labels($start, $period);
        $paid = $this->sales($creatorId, $start, $end, $type)->get(['id', 'buyer_id', 'completed_at', 'total']);
        $time = DB::table('creator_studio_time_entries')->where('user_id', $creatorId)
            ->whereBetween('logged_on', [$start->toDateString(), $end->toDateString()])
            ->when($type !== 'all', fn ($q) => $q->where('work_type', $type))
            ->orderByDesc('logged_on')->get();
        $expenses = DB::table('creator_studio_expenses')->where('user_id', $creatorId)
            ->whereBetween('spent_on', [$start->toDateString(), $end->toDateString()])
            ->when($type !== 'all', fn ($q) => $q->where('work_type', $type))
            ->orderByDesc('spent_on')->get();
        $preferences = DB::table('creator_studio_preferences')->where('user_id', $creatorId)->first();
        $revenue = array_fill(0, $bucketCount, 0.0);
        $billable = array_fill(0, $bucketCount, 0.0);
        $nonBillable = array_fill(0, $bucketCount, 0.0);
        $expenseSeries = array_fill(0, $bucketCount, 0.0);
        foreach ($paid as $order) {
            $index = $this->bucket(CarbonImmutable::parse($order->completed_at), $start, $period);
            $revenue[$index] += (float) $order->total;
        }
        foreach ($time as $entry) {
            $index = $this->bucket(CarbonImmutable::parse($entry->logged_on), $start, $period);
            $key = $entry->is_billable ? 'billable' : 'non_billable';
            if ($key === 'billable') {
                $billable[$index] += (float) $entry->hours;
            } else {
                $nonBillable[$index] += (float) $entry->hours;
            }
        }
        foreach ($expenses as $expense) {
            $index = $this->bucket(CarbonImmutable::parse($expense->spent_on), $start, $period);
            $expenseSeries[$index] += (float) $expense->amount_usd;
        }
        $revenueTotal = round(array_sum($revenue), 2);
        $expenseTotal = round(array_sum($expenseSeries), 2);
        $billableTotal = round(array_sum($billable), 2);
        $nonBillableTotal = round(array_sum($nonBillable), 2);
        $months = match ($period) { 'year' => 12, 'quarter' => 3, default => 1 };
        $monthlyGoal = $preferences?->monthly_goal_usd === null ? null : (float) $preferences->monthly_goal_usd;
        // A global goal cannot fairly be compared with one filtered listing type.
        $goal = $type === 'all' && $monthlyGoal !== null ? $monthlyGoal * $months : null;
        $capacity = $type !== 'all' || $preferences?->weekly_capacity_hours === null ? null : (float) $preferences->weekly_capacity_hours;
        $weekHours = (float) DB::table('creator_studio_time_entries')
            ->where('user_id', $creatorId)
            ->whereBetween('logged_on', [$now->startOfWeek()->toDateString(), $now->toDateString()])
            ->when($type !== 'all', fn ($q) => $q->where('work_type', $type))
            ->sum('hours');
        $orderStatuses = MarketplaceOrder::query()->where('seller_id', $creatorId)
            ->whereBetween('created_at', [$start, $end])
            ->when($type !== 'all', fn (Builder $q) => $q->whereIn('marketplace_listing_id',
                MarketplaceListing::query()->select('id')->where('type', $type)))
            ->select('status')->get()->countBy('status');
        $groupedByBuyer = $paid->groupBy('buyer_id');
        $repeatRevenue = $groupedByBuyer->filter(fn (Collection $orders) => $orders->count() > 1)
            ->flatten(1)->sum(fn ($order) => (float) $order->total);

        $contracts = in_array($type, ['all', 'freelance', 'project'], true)
            ? FreelanceContract::query()->where('freelancer_id', $creatorId)
                ->whereIn('status', ['offered', 'active', 'disputed', 'completed'])
                ->with(['client:id,name', 'milestones'])
                ->latest('updated_at')->limit(8)->get()
            : collect();
        $projects = $contracts->map(function (FreelanceContract $contract) use ($now): array {
            $milestones = $contract->milestones;
            $done = $milestones->where('status', 'accepted')->count();
            $overdue = $milestones->contains(fn ($m) => $m->due_on !== null
                && $m->due_on->isBefore($now->startOfDay()) && $m->status !== 'accepted');
            $health = match (true) {
                $contract->status === 'completed' => 'Completed',
                $contract->status === 'disputed' || $overdue => 'At risk',
                $contract->status === 'offered' => 'Waiting on client',
                default => 'On track',
            };
            return [
                'title' => $contract->title,
                'client' => $contract->client?->name ?? 'Client',
                'status' => $health,
                'progress' => $milestones->count() ? (int) round(100 * $done / $milestones->count()) : null,
                'value' => $contract->currency === 'USD' ? (float) $contract->agreed_budget : null,
                'currency' => $contract->currency,
                'url' => route('contracts.show', $contract),
                'milestones' => $milestones->filter(fn ($m) => $m->due_on !== null)
                    ->map(fn ($m) => [
                        'title' => $m->title,
                        'date' => $m->due_on->toDateString(),
                        'status' => $m->status,
                    ])->values()->all(),
            ];
        })->values()->all();
        $inquiries = ServiceInquiry::query()->where('creator_id', $creatorId)
            ->whereBetween('created_at', [$start, $end])
            ->when($type !== 'all', fn (Builder $q) => $q->whereHas('listing',
                fn (Builder $listing) => $listing->where('type', $type)))
            ->count();
        $proposals = in_array($type, ['all', 'freelance', 'project'], true)
            ? JobProposal::query()->where('freelancer_id', $creatorId)->whereBetween('created_at', [$start, $end])->count() : 0;
        $accepted = in_array($type, ['all', 'freelance', 'project'], true)
            ? FreelanceContract::query()->where('freelancer_id', $creatorId)
                ->whereBetween('accepted_at', [$start, $end])->count() : 0;

        $heatFrom = $now->subDays(83)->toDateString();
        $heat = DB::table('creator_studio_time_entries')->where('user_id', $creatorId)
            ->whereBetween('logged_on', [$heatFrom, $now->toDateString()])
            ->when($type !== 'all', fn ($q) => $q->where('work_type', $type))
            ->select('logged_on', 'hours')->get()
            ->groupBy('logged_on')->map(fn (Collection $items) => round($items->sum('hours'), 2))->all();
        $heatmap = [];
        for ($n = 83; $n >= 0; $n--) {
            $date = $now->subDays($n)->toDateString();
            $heatmap[] = ['date' => $date, 'hours' => (float) ($heat[$date] ?? 0)];
        }
        return [
            'period' => $period, 'type' => $type,
            'start' => $start->toDateString(), 'end' => $end->toDateString(),
            'labels' => $labels,
            'revenue' => $this->rounded($revenue),
            'billable' => $this->rounded($billable),
            'non_billable' => $this->rounded($nonBillable),
            'expenses' => $this->rounded($expenseSeries),
            'total_revenue' => $revenueTotal,
            'total_expenses' => $expenseTotal,
            'sales_minus_expenses' => round($revenueTotal - $expenseTotal, 2),
            'billable_hours' => $billableTotal,
            'non_billable_hours' => $nonBillableTotal,
            'monthly_goal' => $monthlyGoal,
            'goal' => $goal,
            'weekly_capacity_hours' => $capacity,
            'week_hours' => round($weekHours, 2),
            'orders' => [
                'completed' => (int) ($orderStatuses['completed'] ?? 0),
                'awaiting_payment' => (int) ($orderStatuses['awaiting_payment'] ?? 0),
                'refunded' => (int) ($orderStatuses['refunded'] ?? 0),
            ],
            'repeat_sales' => round($repeatRevenue, 2),
            'single_sales' => round($revenueTotal - $repeatRevenue, 2),
            'pipeline' => ['inquiries' => $inquiries, 'proposals' => $proposals, 'accepted_contracts' => $accepted],
            'projects' => $projects,
            'heatmap' => $heatmap,
            'recent_time' => $time->take(5)->map(fn ($e) => [
                'id' => $e->id, 'date' => $e->logged_on, 'hours' => (float) $e->hours,
                'billable' => (bool) $e->is_billable, 'type' => $e->work_type, 'description' => $e->description,
            ])->values()->all(),
            'recent_expenses' => $expenses->take(5)->map(fn ($e) => [
                'id' => $e->id, 'date' => $e->spent_on, 'amount' => (float) $e->amount_usd,
                'category' => $e->category, 'type' => $e->work_type,
                'description' => $e->description,
            ])->values()->all(),
            'commerce_enabled' => (bool) config('commerce.enabled'),
        ];
    }

    private function sales(int $id, CarbonImmutable $start, CarbonImmutable $end, string $type): Builder
    {
        return MarketplaceOrder::query()->where('seller_id', $id)
            ->where('status', 'completed')->where('currency', 'USD')
            ->whereBetween('completed_at', [$start, $end])
            ->whereHas('paidAttempt')
            ->whereDoesntHave('operations', fn (Builder $q) => $q->where('refund_status', 'refunded'))
            ->when($type !== 'all', fn (Builder $query) => $query->whereIn('marketplace_listing_id',
                MarketplaceListing::query()->select('id')->where('type', $type)));
    }

    private function labels(CarbonImmutable $start, string $period): array
    {
        if ($period === 'month') {
            return [['Week 1', 'Week 2', 'Week 3', 'Week 4', 'Week 5'], 5];
        }
        $size = $period === 'quarter' ? 3 : 12;
        return [array_map(fn (int $n) => $start->addMonths($n)->format('M'),
            range(0, $size - 1)), $size];
    }

    private function bucket(CarbonImmutable $date, CarbonImmutable $start, string $period): int
    {
        return match ($period) {
            'month' => min(4, intdiv($date->day - 1, 7)),
            'quarter' => $date->month - $start->month,
            default => $date->month - 1,
        };
    }

    private function rounded(array $values): array
    {
        return array_map(static fn ($value) => round((float) $value, 2), $values);
    }
}
