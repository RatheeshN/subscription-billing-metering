<?php

namespace App\Services;

use App\Repositories\Contracts\UsageAggregateRepositoryInterface;
use InvalidArgumentException;

class AggregationService
{
    public function __construct(private UsageAggregateRepositoryInterface $aggregates) {}

    public function chunk(?int $subscriptionId = null): int
    {
        $size = (int) config('billing.aggregation_chunk_size');
        if ($size < 1 || $size > 10000) {
            throw new InvalidArgumentException('Aggregation chunk size must be between 1 and 10000.');
        }

        return $this->aggregates->processPendingChunk($size, $subscriptionId);
    }

    public function drainSubscription(int $subscriptionId): void
    {
        while ($this->chunk($subscriptionId) > 0) {
        }
    }
}
