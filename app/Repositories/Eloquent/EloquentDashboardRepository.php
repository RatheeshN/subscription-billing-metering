<?php

namespace App\Repositories\Eloquent;

use App\Models\DailyUsageAggregate;
use App\Repositories\Contracts\DashboardRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class EloquentDashboardRepository implements DashboardRepositoryInterface
{
    public function getDailyUsage(int $merchantId, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return DailyUsageAggregate::query()
            ->where('merchant_id', $merchantId)
            ->whereBetween('usage_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('usage_date, SUM(units) AS usage_units')
            ->groupBy('usage_date')->orderBy('usage_date')->get()
            ->mapWithKeys(fn ($row) => [$row->usage_date->toDateString() => (int) $row->usage_units]);
    }

    public function getTopCustomersByUsage(int $merchantId, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): Collection
    {
        return DailyUsageAggregate::query()->join('customers', 'customers.id', '=', 'daily_usage_aggregates.customer_id')
            ->where('daily_usage_aggregates.merchant_id', $merchantId)->where('customers.merchant_id', $merchantId)
            ->where('usage_date', '>=', $monthStart->toDateString())->where('usage_date', '<', $monthEnd->toDateString())
            ->selectRaw('customer_id, customers.name, SUM(units) AS usage_units')->groupBy('customer_id', 'customers.name')
            ->orderByDesc('usage_units')->orderBy('customer_id')->limit(5)->get()->map(fn ($row) => ['customer_id' => (int) $row->customer_id, 'name' => $row->name, 'usage_units' => (int) $row->usage_units]);
    }

    public function getChurnRiskCustomers(int $merchantId, CarbonImmutable $asOf): Collection
    {
        $end = $asOf->startOfMonth();
        $recent = $end->subMonth();
        $previous = $end->subMonths(2);

        return DailyUsageAggregate::query()->join('customers', 'customers.id', '=', 'daily_usage_aggregates.customer_id')
            ->where('daily_usage_aggregates.merchant_id', $merchantId)->where('customers.merchant_id', $merchantId)
            ->where('usage_date', '>=', $previous->toDateString())->where('usage_date', '<', $end->toDateString())
            ->selectRaw('customer_id, customers.name, SUM(CASE WHEN usage_date < ? THEN units ELSE 0 END) AS previous_units, SUM(CASE WHEN usage_date >= ? THEN units ELSE 0 END) AS recent_units', [$recent->toDateString(), $recent->toDateString()])
            ->groupBy('customer_id', 'customers.name')->havingRaw('SUM(CASE WHEN usage_date < ? THEN units ELSE 0 END) > 0', [$recent->toDateString()])
            ->havingRaw('2 * SUM(CASE WHEN usage_date >= ? THEN units ELSE 0 END) < SUM(CASE WHEN usage_date < ? THEN units ELSE 0 END)', [$recent->toDateString(), $recent->toDateString()])
            ->orderBy('customer_id')->limit(100)->get()->map(fn ($row) => ['customer_id' => (int) $row->customer_id, 'name' => $row->name, 'previous_usage_units' => (int) $row->previous_units, 'recent_usage_units' => (int) $row->recent_units]);
    }
}
