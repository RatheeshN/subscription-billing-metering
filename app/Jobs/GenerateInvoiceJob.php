<?php

namespace App\Jobs;

use App\Services\BillingService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateInvoiceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public array $backoff = [1, 5, 15, 30];

    public function __construct(public int $merchantId, public int $subscriptionId, public string $cycleStart)
    {
        $this->onQueue('billing');
    }

    public function uniqueId(): string
    {
        return $this->subscriptionId.':'.$this->cycleStart;
    }

    public function handle(BillingService $billing): void
    {
        $billing->generate($this->merchantId, $this->subscriptionId, CarbonImmutable::parse($this->cycleStart, 'UTC'));
    }
}
