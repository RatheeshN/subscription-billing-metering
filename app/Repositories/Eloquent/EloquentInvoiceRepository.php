<?php

namespace App\Repositories\Eloquent;

use App\Models\Invoice;
use App\Models\Subscription;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use App\Support\BillingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentInvoiceRepository implements InvoiceRepositoryInterface
{
    public function forCycle(int $subscriptionId, CarbonImmutable $cycleStart): ?Invoice
    {
        return Invoice::query()->where('subscription_id', $subscriptionId)->where('cycle_starts_at', $cycleStart)->with('lines')->first();
    }

    public function finalize(Subscription $subscription, BillingPeriod $period, array $lines, int $baseMinor, int $overageMinor, int $totalMinor): Invoice
    {
        $invoice = Invoice::query()->create(['merchant_id' => $subscription->merchant_id, 'customer_id' => $subscription->customer_id, 'subscription_id' => $subscription->id, 'cycle_starts_at' => $period->start, 'cycle_ends_at' => $period->end, 'currency' => $subscription->currency, 'base_total_minor' => $baseMinor, 'overage_total_minor' => $overageMinor, 'total_minor' => $totalMinor]);
        $invoice->lines()->createMany($lines);

        return $invoice->load('lines');
    }

    public function listForMerchant(int $merchantId): LengthAwarePaginator
    {
        return Invoice::query()->where('merchant_id', $merchantId)->orderByDesc('id')->paginate(50);
    }

    public function forMerchant(int $merchantId, int $invoiceId): Invoice
    {
        return Invoice::query()->where('merchant_id', $merchantId)->with('lines')->findOrFail($invoiceId);
    }
}
