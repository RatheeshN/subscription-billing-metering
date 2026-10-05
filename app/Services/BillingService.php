<?php

namespace App\Services;

use App\Exceptions\BillingConflictException;
use App\Models\Invoice;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Repositories\Contracts\UsageAggregateRepositoryInterface;
use App\Support\BillingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class BillingService
{
    public function __construct(private SubscriptionRepositoryInterface $subscriptions, private UsageAggregateRepositoryInterface $aggregates, private InvoiceRepositoryInterface $invoices, private AggregationService $aggregation, private BillingCalculator $calculator) {}

    public function generate(int $merchantId, int $subscriptionId, CarbonImmutable $cycleStart): Invoice
    {
        return DB::transaction(function () use ($merchantId, $subscriptionId, $cycleStart): Invoice {
            $subscription = $this->subscriptions->lockForMerchant($merchantId, $subscriptionId);
            if ($existing = $this->invoices->forCycle($subscriptionId, $cycleStart)) {
                return $existing;
            }
            if (! $subscription->cycle_starts_at->equalTo($cycleStart)) {
                throw new BillingConflictException('Invoices must be generated in chronological cycle order.');
            }
            $period = new BillingPeriod($subscription->cycle_starts_at, $subscription->cycle_ends_at);
            if ($period->end->addSeconds((int) config('billing.invoice_grace_seconds')) > CarbonImmutable::now('UTC')) {
                throw new BillingConflictException('The billing cycle is not ready for finalization.');
            }
            $this->aggregation->drainSubscription($subscriptionId);
            $subscription = $this->subscriptions->forMerchant($merchantId, $subscriptionId);
            $usage = $this->aggregates->totalsForSegments($subscription->segments->modelKeys(), $period, lockForUpdate: true);
            $lines = [];
            $coveredThrough = $subscription->starts_at->max($period->start);
            foreach ($subscription->segments as $segment) {
                $start = $segment->starts_at->max($period->start);
                $end = ($segment->ends_at ?? $period->end)->min($period->end);
                if ($start >= $end) {
                    continue;
                }
                if (! $start->equalTo($coveredThrough)) {
                    throw new BillingConflictException('Subscription pricing history has a gap or overlap.');
                }
                $coveredThrough = $end;
                $duration = $end->getTimestamp() - $start->getTimestamp();
                $line = $this->calculator->calculate($segment->base_price_minor, $segment->included_usage_units, $segment->overage_rate_micros, $usage[$segment->id] ?? 0, $duration, $period->seconds());
                $lines[] = $line->toArray() + ['subscription_segment_id' => $segment->id, 'description' => $segment->plan_name, 'starts_at' => $start->toDateTimeString(), 'ends_at' => $end->toDateTimeString(), 'duration_seconds' => $duration, 'cycle_seconds' => $period->seconds(), 'base_price_minor' => $segment->base_price_minor, 'overage_rate_micros' => $segment->overage_rate_micros];
            }
            if (! $coveredThrough->equalTo($period->end)) {
                throw new BillingConflictException('Subscription pricing history does not cover the billing cycle.');
            }
            $base = $this->calculator->sum(array_column($lines, 'base_total_minor'));
            $overage = $this->calculator->sum(array_column($lines, 'overage_total_minor'));
            $invoice = $this->invoices->finalize($subscription, $period, $lines, $base, $overage, $this->calculator->sum([$base, $overage]));
            $next = BillingPeriod::containing($period->end, $subscription->billing_cycle);
            $this->subscriptions->advanceCycle($subscription, $next->start, $next->end);

            return $invoice;
        }, 5);
    }
}
