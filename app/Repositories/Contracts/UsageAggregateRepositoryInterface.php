<?php

namespace App\Repositories\Contracts;

use App\Support\BillingPeriod;

interface UsageAggregateRepositoryInterface
{
    public function processPendingChunk(int $size, ?int $subscriptionId = null): int;

    /** @param array<int> $segmentIds @return array<int, int> */
    public function totalsForSegments(array $segmentIds, BillingPeriod $period, bool $lockForUpdate = false): array;
}
