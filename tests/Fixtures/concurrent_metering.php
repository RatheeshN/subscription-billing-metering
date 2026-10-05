<?php

use App\Actions\RecordUsageAction;
use App\DTOs\RecordUsageDTO;
use App\Models\Subscription;
use App\Services\AggregationService;
use App\Services\BillingService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
    throw new RuntimeException('Concurrency workers require an isolated MySQL test database.');
}
echo "READY\n";
flush();
file_put_contents($argv[2].'.ready.'.$argv[5], 'ready');
$deadline = microtime(true) + 45;
while (! file_exists($argv[2])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Timed out waiting for the concurrency barrier.');
    }
    usleep(10000);
}
if ($argv[1] === 'record') {
    $event = $app->make(RecordUsageAction::class)->handle(new RecordUsageDTO((int) $argv[3], (int) $argv[4], 50, CarbonImmutable::parse('2026-09-30T10:30:00Z'), 'concurrent-request'));
    echo 'EVENT:'.$event->id."\n";
} elseif ($argv[1] === 'bill') {
    $subscription = Subscription::query()->where('merchant_id', (int) $argv[3])->where('customer_id', (int) $argv[4])->sole();
    $invoice = CarbonImmutable::withTestNow('2026-10-01 00:10:00', fn () => $app->make(BillingService::class)->generate(
        (int) $argv[3], $subscription->id, CarbonImmutable::parse('2026-09-01', 'UTC'),
    ));
    echo 'INVOICE:'.$invoice->id."\n";
} else {
    config(['billing.aggregation_chunk_size' => 5]);
    $aggregation = $app->make(AggregationService::class);
    while ($aggregation->chunk() > 0) {
    }
    echo "AGGREGATED\n";
}
