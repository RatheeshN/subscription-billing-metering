<?php

namespace App\Repositories\Eloquent;

use App\DTOs\RecordUsageDTO;
use App\Models\SubscriptionSegment;
use App\Models\UsageEvent;
use App\Repositories\Contracts\UsageEventRepositoryInterface;
use Carbon\CarbonImmutable;

class EloquentMeteredUsageEventRepository implements UsageEventRepositoryInterface
{
    public function findRetry(RecordUsageDTO $usage): ?UsageEvent
    {
        return UsageEvent::query()->where('merchant_id', $usage->merchantId)->where('customer_id', $usage->customerId)->where('idempotency_key', $usage->idempotencyKey)->first();
    }

    public function recordUsage(RecordUsageDTO $usage, SubscriptionSegment $segment): UsageEvent
    {
        return UsageEvent::query()->createOrFirst(
            ['customer_id' => $usage->customerId, 'idempotency_key' => $usage->idempotencyKey],
            ['merchant_id' => $usage->merchantId, 'subscription_segment_id' => $segment->id, 'units' => $usage->units, 'occurred_at' => $usage->occurredAt, 'usage_date' => $usage->occurredAt->toDateString()],
        );
    }

    public function hasUsageAtOrAfter(SubscriptionSegment $segment, CarbonImmutable $time): bool
    {
        return UsageEvent::query()->where('subscription_segment_id', $segment->id)->where('occurred_at', '>=', $time)->exists();
    }
}
