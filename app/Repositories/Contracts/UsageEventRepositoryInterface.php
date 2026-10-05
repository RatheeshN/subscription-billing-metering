<?php

namespace App\Repositories\Contracts;

use App\DTOs\RecordUsageDTO;
use App\Models\SubscriptionSegment;
use App\Models\UsageEvent;
use Carbon\CarbonImmutable;

interface UsageEventRepositoryInterface
{
    public function findRetry(RecordUsageDTO $usage): ?UsageEvent;

    public function recordUsage(RecordUsageDTO $usage, SubscriptionSegment $segment): UsageEvent;

    public function hasUsageAtOrAfter(SubscriptionSegment $segment, CarbonImmutable $time): bool;
}
