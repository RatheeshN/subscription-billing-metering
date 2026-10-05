<?php

namespace Tests\Feature\Services;

use App\Jobs\AggregateDailyUsageJob;
use App\Models\SubscriptionSegment;
use App\Models\UsageEvent;
use App\Services\AggregationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AggregationServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_aggregates_events_in_small_chunks_and_reruns_without_double_counting(): void
    {
        $segment = SubscriptionSegment::factory()->create();
        UsageEvent::factory()->metered($segment)->count(5)->create(['units' => 7]);
        config(['billing.aggregation_chunk_size' => 2]);
        $service = $this->app->make(AggregationService::class);

        $this->assertSame(2, $service->chunk());
        $this->assertSame(2, $service->chunk());
        $this->assertSame(1, $service->chunk());
        $this->assertSame(0, $service->chunk());

        $this->assertDatabaseCount('daily_usage_aggregates', 1);
        $this->assertDatabaseHas('daily_usage_aggregates', ['subscription_segment_id' => $segment->id, 'units' => 35]);
        $this->assertSame(0, UsageEvent::query()->whereNull('aggregated_at')->count());
    }

    public function test_separates_days_and_pricing_segments(): void
    {
        $first = SubscriptionSegment::factory()->create();
        $second = SubscriptionSegment::factory()->create();
        UsageEvent::factory()->metered($first)->create(['units' => 5, 'occurred_at' => '2026-09-01 10:00:00']);
        UsageEvent::factory()->metered($first)->create(['units' => 10, 'occurred_at' => '2026-09-02 10:00:00']);
        UsageEvent::factory()->metered($second)->create(['units' => 20, 'occurred_at' => '2026-09-01 10:00:00']);

        $this->app->make(AggregationService::class)->chunk();

        $this->assertDatabaseCount('daily_usage_aggregates', 3);
        $this->assertDatabaseHas('daily_usage_aggregates', ['subscription_segment_id' => $first->id, 'usage_date' => '2026-09-01', 'units' => 5]);
        $this->assertDatabaseHas('daily_usage_aggregates', ['subscription_segment_id' => $first->id, 'usage_date' => '2026-09-02', 'units' => 10]);
        $this->assertDatabaseHas('daily_usage_aggregates', ['subscription_segment_id' => $second->id, 'units' => 20]);
    }

    public function test_late_unbilled_events_increment_existing_daily_total_once(): void
    {
        $segment = SubscriptionSegment::factory()->create();
        $service = $this->app->make(AggregationService::class);
        UsageEvent::factory()->metered($segment)->create(['units' => 7]);
        $service->chunk();
        UsageEvent::factory()->metered($segment)->create(['units' => 3]);

        $service->chunk();
        $service->chunk();

        $this->assertDatabaseHas('daily_usage_aggregates', ['units' => 10]);
        $this->assertDatabaseCount('daily_usage_aggregates', 1);
    }

    public function test_billing_drain_only_processes_its_subscription(): void
    {
        $first = SubscriptionSegment::factory()->create();
        $second = SubscriptionSegment::factory()->create();
        UsageEvent::factory()->metered($first)->create();
        UsageEvent::factory()->metered($second)->create();

        $this->app->make(AggregationService::class)->drainSubscription($first->subscription_id);

        $this->assertDatabaseHas('usage_events', ['subscription_segment_id' => $second->id, 'aggregated_at' => null]);
        $this->assertDatabaseCount('daily_usage_aggregates', 1);
    }

    public function test_failed_marker_write_rolls_back_totals_and_a_job_retry_counts_usage_once(): void
    {
        $segment = SubscriptionSegment::factory()->create();
        $service = $this->app->make(AggregationService::class);
        UsageEvent::factory()->metered($segment)->create(['units' => 7]);
        $service->chunk();
        $pending = UsageEvent::factory()->metered($segment)->create(['units' => 3]);
        $failed = false;
        DB::listen(function (QueryExecuted $query) use (&$failed): void {
            if (! $failed && str_starts_with($query->sql, 'update') && str_contains($query->sql, 'usage_events')) {
                $failed = true;
                throw new \RuntimeException('Simulated marker write failure');
            }
        });

        try {
            $service->chunk();
            $this->fail('Expected aggregation to fail before commit.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated marker write failure', $exception->getMessage());
        }

        $this->assertDatabaseHas('daily_usage_aggregates', ['subscription_segment_id' => $segment->id, 'units' => 7]);
        $this->assertDatabaseHas('usage_events', ['id' => $pending->id, 'aggregated_at' => null]);

        (new AggregateDailyUsageJob)->handle($service);
        (new AggregateDailyUsageJob)->handle($service);

        $this->assertDatabaseHas('daily_usage_aggregates', ['subscription_segment_id' => $segment->id, 'units' => 10]);
        $this->assertNotNull($pending->fresh()->aggregated_at);
    }

    public function test_queued_job_bounds_work_and_dispatches_continuation(): void
    {
        $segment = SubscriptionSegment::factory()->create();
        UsageEvent::factory()->metered($segment)->count(3)->create(['units' => 1]);
        config(['billing.aggregation_chunk_size' => 1, 'billing.aggregation_chunks_per_job' => 2]);
        Queue::fake([AggregateDailyUsageJob::class]);

        (new AggregateDailyUsageJob)->handle($this->app->make(AggregationService::class));

        $this->assertDatabaseHas('daily_usage_aggregates', ['units' => 2]);
        Queue::assertPushed(AggregateDailyUsageJob::class, 1);
    }
}
