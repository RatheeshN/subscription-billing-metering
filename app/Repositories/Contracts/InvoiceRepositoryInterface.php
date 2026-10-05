<?php

namespace App\Repositories\Contracts;

use App\Models\Invoice;
use App\Models\Subscription;
use App\Support\BillingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface InvoiceRepositoryInterface
{
    public function forCycle(int $subscriptionId, CarbonImmutable $cycleStart): ?Invoice;

    /** @param array<int, array<string, int|string>> $lines */
    public function finalize(Subscription $subscription, BillingPeriod $period, array $lines, int $baseMinor, int $overageMinor, int $totalMinor): Invoice;

    public function listForMerchant(int $merchantId): LengthAwarePaginator;

    public function forMerchant(int $merchantId, int $invoiceId): Invoice;
}
