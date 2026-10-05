<?php

namespace App\Services;

use App\Repositories\Contracts\DashboardRepositoryInterface;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Repositories\Contracts\UsageAggregateRepositoryInterface;
use App\Support\BillingPeriod;
use Carbon\CarbonImmutable;

class DashboardService
{
    public function __construct(private DashboardRepositoryInterface $dashboard, private SubscriptionRepositoryInterface $subscriptions, private UsageAggregateRepositoryInterface $aggregates, private BillingCalculator $calculator) {}

    /** @return array<string, mixed> */
    public function get(int $merchantId): array
    {
        $asOf = CarbonImmutable::now('UTC')->startOfSecond();
        $monthStart = $asOf->startOfMonth();
        $byCurrency = [];
        foreach ($this->subscriptions->currentForMerchant($merchantId, $asOf)->chunk(100) as $subscriptions) {
            foreach ($subscriptions->groupBy(fn ($subscription) => $subscription->billing_cycle->value) as $cycleSubscriptions) {
                $period = BillingPeriod::containing($asOf, $cycleSubscriptions->first()->billing_cycle);
                $segmentIds = $cycleSubscriptions->flatMap(fn ($subscription) => $subscription->segments->modelKeys())->all();
                $totals = $this->aggregates->totalsForSegments($segmentIds, $period);
                foreach ($cycleSubscriptions as $subscription) {
                    foreach ($subscription->segments as $segment) {
                        $start = $segment->starts_at->max($period->start);
                        $end = ($segment->ends_at ?? $period->end)->min($period->end);
                        if ($start >= $end || $start >= $asOf) {
                            continue;
                        }
                        $units = $totals[$segment->id] ?? 0;
                        $duration = $end->getTimestamp() - $start->getTimestamp();
                        if ($end > $asOf) {
                            $elapsed = $asOf->getTimestamp() - $start->getTimestamp();
                            $units = $this->calculator->ratio($units, $duration, $elapsed);
                        }
                        $line = $this->calculator->calculate($segment->base_price_minor, $segment->included_usage_units, $segment->overage_rate_micros, $units, $duration, $period->seconds());
                        $byCurrency[$subscription->currency] = $this->calculator->sum([
                            $byCurrency[$subscription->currency] ?? 0,
                            $line->overageTotalMinor,
                        ]);
                    }
                }
            }
        }
        ksort($byCurrency);

        return [
            'as_of' => $asOf->toIso8601String(),
            'usage_source' => 'daily_usage_aggregates',
            'top_customers' => $this->dashboard->getTopCustomersByUsage($merchantId, $monthStart, $monthStart->addMonth()),
            'projected_overage_revenue' => $byCurrency,
            'churn_risk_customers' => $this->dashboard->getChurnRiskCustomers($merchantId, $asOf),
            'churn_risk_limit' => 100,
            'churn_windows' => ['previous_start' => $monthStart->subMonths(2)->toDateString(), 'recent_start' => $monthStart->subMonth()->toDateString(), 'recent_end' => $monthStart->toDateString()],
        ];
    }
}
