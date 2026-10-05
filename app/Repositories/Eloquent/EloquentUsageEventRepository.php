<?php

namespace App\Repositories\Eloquent;

use App\Models\Customer;
use App\Models\UsageEvent;
use App\Repositories\Contracts\UsageEventRepository;

class EloquentUsageEventRepository implements UsageEventRepository
{
    public function record(Customer $customer, string $idempotencyKey, int $units, string $usageDate): UsageEvent
    {
        return $customer->usageEvents()->createOrFirst(
            ['idempotency_key' => $idempotencyKey],
            ['units' => $units, 'usage_date' => $usageDate],
        );
    }

    public function totalUnits(Customer $customer, string $startDate, string $endDate): int
    {
        return (int) $customer->usageEvents()
            ->where('usage_date', '>=', $startDate)
            ->where('usage_date', '<', $endDate)
            ->sum('units');
    }
}
