<?php

namespace Tests\Feature\Integration;

use App\Models\DailyUsageAggregate;
use App\Models\Invoice;
use App\Models\SubscriptionSegment;
use App\Models\UsageEvent;
use App\Services\BillingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MySqlConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Run with phpunit.mysql.xml to exercise MySQL row locks.');
        }
        if (! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
            throw new \RuntimeException('Concurrency tests require an isolated database ending in _test.');
        }
    }

    private function race(string $mode, SubscriptionSegment $segment, int $workers): array
    {
        $connection = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $connection['database'], 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'];
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'metering-race-'.bin2hex(random_bytes(12));
        $processes = [];
        try {
            for ($index = 0; $index < $workers; $index++) {
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent_metering.php'), $mode, $barrier, (string) $segment->subscription->merchant_id, (string) $segment->subscription->customer_id, (string) $index], base_path(), $environment, null, 60);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 40;
            foreach ($processes as $index => $process) {
                while (! file_exists($barrier.'.ready.'.$index) && $process->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertFileExists($barrier.'.ready.'.$index, $process->getOutput().$process->getErrorOutput());
            }
            file_put_contents($barrier, 'go');
            $outputs = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
                $outputs[] = $process->getOutput();
            }

            return $outputs;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            if (file_exists($barrier)) {
                unlink($barrier);
            }
            for ($index = 0; $index < $workers; $index++) {
                if (file_exists($barrier.'.ready.'.$index)) {
                    unlink($barrier.'.ready.'.$index);
                }
            }
        }
    }

    public function test_concurrent_ingestion_creates_only_one_usage_event(): void
    {
        $segment = SubscriptionSegment::factory()->create();

        $outputs = $this->race('record', $segment, 4);

        $this->assertDatabaseCount('usage_events', 1);
        $event = UsageEvent::query()->sole();
        foreach ($outputs as $output) {
            $this->assertStringContainsString('EVENT:'.$event->id, $output);
        }
    }

    public function test_concurrent_aggregation_workers_count_each_event_once(): void
    {
        $segment = SubscriptionSegment::factory()->create();
        UsageEvent::factory()->metered($segment)->count(50)->create(['units' => 7]);

        $this->race('aggregate', $segment, 4);

        $this->assertDatabaseCount('daily_usage_aggregates', 1);
        $this->assertDatabaseHas('daily_usage_aggregates', ['subscription_segment_id' => $segment->id, 'units' => 350]);
        $this->assertSame(0, UsageEvent::query()->whereNull('aggregated_at')->count());
    }

    public function test_concurrent_billing_workers_return_one_invoice_and_advance_the_cycle_once(): void
    {
        $segment = SubscriptionSegment::factory()->create();
        UsageEvent::factory()->metered($segment)->create(['units' => 150]);

        $outputs = $this->race('bill', $segment, 4);

        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_lines', 1);
        $invoice = Invoice::query()->sole();
        $this->assertSame(10050, $invoice->total_minor);
        $this->assertSame(150, $invoice->lines->sole()->usage_units);
        $this->assertSame('2026-10-01', $segment->subscription->fresh()->cycle_starts_at->toDateString());
        foreach ($outputs as $output) {
            $this->assertStringContainsString('INVOICE:'.$invoice->id, $output);
        }
    }

    public function test_invoice_includes_aggregate_committed_after_its_repeatable_read_snapshot(): void
    {
        $segment = SubscriptionSegment::factory()->create();
        DailyUsageAggregate::factory()->for($segment, 'subscriptionSegment')->create(['units' => 100, 'usage_date' => '2026-09-01']);
        UsageEvent::factory()->metered($segment)->count(50)->create(['units' => 7]);
        config(['database.connections.aggregation_writer' => config('database.connections.mysql')]);
        $applied = false;
        DB::listen(function (QueryExecuted $query) use (&$applied): void {
            if ($applied || $query->connectionName !== 'mysql' || ! str_contains($query->sql, 'from `invoices`')) {
                return;
            }
            $applied = true;
            $writer = DB::connection('aggregation_writer');
            $writer->transaction(function () use ($writer): void {
                $writer->table('daily_usage_aggregates')->increment('units', 350);
                $writer->table('usage_events')->update(['aggregated_at' => now()]);
            });
        });
        $this->travelTo(CarbonImmutable::parse('2026-10-01 00:10:00', 'UTC'));

        $invoice = $this->app->make(BillingService::class)->generate($segment->subscription->merchant_id, $segment->subscription_id, CarbonImmutable::parse('2026-09-01', 'UTC'));

        $this->assertTrue($applied);
        $this->assertSame(450, $invoice->lines->first()->usage_units);
        $this->assertSame(10350, $invoice->total_minor);
        DB::disconnect('aggregation_writer');
    }
}
