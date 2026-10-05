<?php

namespace App\Jobs;

use App\Services\AggregationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AggregateDailyUsageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public array $backoff = [1, 5, 15, 30];

    public function __construct()
    {
        $this->onQueue('metering');
    }

    public function handle(AggregationService $aggregation): void
    {
        $limit = (int) config('billing.aggregation_chunks_per_job');
        for ($chunk = 0; $chunk < $limit; $chunk++) {
            if ($aggregation->chunk() < (int) config('billing.aggregation_chunk_size')) {
                return;
            }
        }
        self::dispatch()->afterCommit();
    }
}
