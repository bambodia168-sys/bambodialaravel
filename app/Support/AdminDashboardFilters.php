<?php

namespace App\Support;

use App\Models\MarketplaceListing;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Read-only calendar reporting for the administrator overview only. */
final class AdminDashboardFilters
{
    public const TYPES = ['all'=>'All', 'product'=>'Products', 'project'=>'Projects', 'freelance'=>'Freelance', 'service'=>'Service'];
    public const PERIODS = ['month'=>'This month', 'quarter'=>'Quarter', 'year'=>'Year'];

    public readonly CarbonImmutable $start;
    public readonly CarbonImmutable $end;

    private function __construct(public readonly string $type, public readonly string $period)
    {
        $now = CarbonImmutable::now();
        $this->start = match ($period) {
            'quarter' => $now->startOfQuarter(),
            'year' => $now->startOfYear(),
            default => $now->startOfMonth(),
        };
        $this->end = $now;
    }

    public static function fromRequest(Request $request): self
    {
        $type = $request->query('type');
        $period = $request->query('period');

        return new self(
            is_string($type) && isset(self::TYPES[$type]) ? $type : 'all',
            is_string($period) && isset(self::PERIODS[$period]) ? $period : 'month',
        );
    }

    public function dated(Builder $query): Builder
    {
        return $query->whereBetween('created_at', [$this->start, $this->end]);
    }

    public function listings(Builder $query): Builder
    {
        return $this->dated($query)->when($this->type !== 'all', fn (Builder $q) => $q->where('type', $this->type));
    }

    public function orders(Builder $query): Builder
    {
        return $this->dated($query)->when($this->type !== 'all', fn (Builder $q) => $q->whereIn(
            'marketplace_listing_id', MarketplaceListing::query()->where('type', $this->type)->select('id')
        ));
    }

    public function rangeLabel(): string
    {
        return $this->start->format('d M Y').' – '.$this->end->format('d M Y');
    }

    /** The same creation-date window as the KPIs; no artificial future buckets. */
    public function trend(string $title, string $description, string $icon, Builder $query): array
    {
        $daily = $query->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupByRaw('DATE(created_at)')->pluck('total', 'day');
        $buckets = [];
        $monthly = $this->period === 'year';
        for ($day = $this->start; $day->lte($this->end); $day = $day->addDay()) {
            $bucket = $monthly ? $day->startOfMonth() : $this->start->addDays(intdiv((int) $this->start->diffInDays($day), 7) * 7);
            $key = $bucket->toDateString();
            $buckets[$key] ??= ['label' => $bucket->format($monthly ? 'M' : 'd M'), 'count' => 0];
            $buckets[$key]['count'] += (int) ($daily[$day->toDateString()] ?? 0);
        }

        return ['title'=>$title, 'description'=>$description, 'icon'=>$icon, 'buckets'=>array_values($buckets),
            'interval'=>$monthly ? 'Monthly' : 'Weekly'];
    }
}
