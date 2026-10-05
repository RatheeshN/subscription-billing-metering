<?php

namespace App\Console\Commands;

use App\Jobs\GenerateInvoiceJob;
use App\Models\Subscription;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class DispatchBilling extends Command
{
    protected $signature = 'billing:dispatch';

    protected $description = 'Queue one due cycle per subscription in bounded database chunks';

    public function handle(SubscriptionRepositoryInterface $subscriptions): int
    {
        $count = 0;
        $cutoff = CarbonImmutable::now('UTC')->subSeconds((int) config('billing.invoice_grace_seconds'));
        $subscriptions->eachDue($cutoff, function (Subscription $subscription) use (&$count): void {
            GenerateInvoiceJob::dispatch($subscription->merchant_id, $subscription->id, $subscription->cycle_starts_at->toDateTimeString())->afterCommit();
            $count++;
        });
        $this->info("Dispatched {$count} due billing cycles.");

        return self::SUCCESS;
    }
}
