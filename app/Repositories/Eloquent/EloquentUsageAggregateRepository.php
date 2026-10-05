<?php

namespace App\Repositories\Eloquent;

use App\Models\DailyUsageAggregate;
use App\Models\SubscriptionSegment;
use App\Models\UsageEvent;
use App\Repositories\Contracts\UsageAggregateRepositoryInterface;
use App\Support\BillingPeriod;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;

class EloquentUsageAggregateRepository implements UsageAggregateRepositoryInterface
{
    public function processPendingChunk(int $size, ?int $subscriptionId = null): int
    {
        return DB::transaction(function () use ($size, $subscriptionId): int {
            $events = UsageEvent::query()->whereNull('aggregated_at')->whereNotNull('subscription_segment_id')
                ->when($subscriptionId !== null, fn ($query) => $query->whereIn('subscription_segment_id', SubscriptionSegment::query()->where('subscription_id', $subscriptionId)->select('id')))
                ->orderBy('id')->limit($size)->lockForUpdate()->get(['id', 'merchant_id', 'customer_id', 'subscription_segment_id', 'usage_date', 'units']);
            foreach ($events->groupBy(fn (UsageEvent $event) => $event->subscription_segment_id.':'.$event->usage_date->toDateString())->sortKeys() as $group) {
                $first = $group->first();
                $aggregate = DailyUsageAggregate::query()->lockForUpdate()->createOrFirst(
                    ['subscription_segment_id' => $first->subscription_segment_id, 'usage_date' => $first->usage_date->toDateString()],
                    ['merchant_id' => $first->merchant_id, 'customer_id' => $first->customer_id, 'units' => 0],
                );
                $aggregate->increment('units', $group->sum('units'));
            }
            if ($events->isNotEmpty()) {
                UsageEvent::query()->whereKey($events->modelKeys())->update(['aggregated_at' => now()]);
            }

            return $events->count();
        }, 5);
    }

    public function totalsForSegments(array $segmentIds, BillingPeriod $period, bool $lockForUpdate = false): array
    {
        if ($segmentIds === []) {
            return [];
        }

        $query = DailyUsageAggregate::query()->whereIn('subscription_segment_id', $segmentIds)->where('usage_date', '>=', $period->start->toDateString())->where('usage_date', '<', $period->end->toDateString());

        if ($lockForUpdate) {
            return $query->orderBy('id')->lockForUpdate()->get(['subscription_segment_id', 'units'])
                ->groupBy('subscription_segment_id')->map(fn ($rows) => $rows->reduce(fn (BigInteger $sum, DailyUsageAggregate $row) => $sum->plus($row->units), BigInteger::zero())->toInt())->all();
        }

        return $query->selectRaw('subscription_segment_id, SUM(units) AS total_units')->groupBy('subscription_segment_id')->get()->mapWithKeys(fn ($row) => [(int) $row->subscription_segment_id => BigInteger::of((string) $row->total_units)->toInt()])->all();
    }
}
