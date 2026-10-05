<?php

namespace App\Repositories\Eloquent;

use App\DTOs\PricingDTO;
use App\Exceptions\BillingConflictException;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\SubscriptionSegment;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Support\BillingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\LazyCollection;

class EloquentSubscriptionRepository implements SubscriptionRepositoryInterface
{
    public function lockForCustomer(int $merchantId, int $customerId): Subscription
    {
        return Subscription::query()->where('merchant_id', $merchantId)->where('customer_id', $customerId)->lockForUpdate()->firstOrFail();
    }

    public function lockForMerchant(int $merchantId, int $subscriptionId): Subscription
    {
        return Subscription::query()->where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($subscriptionId);
    }

    public function start(Customer $customer, PricingDTO $pricing, CarbonImmutable $startsAt): Subscription
    {
        if (Subscription::query()->where('customer_id', $customer->id)->exists()) {
            throw new BillingConflictException('The customer already has a subscription.');
        }
        $period = BillingPeriod::containing($startsAt, $pricing->billingCycle);
        $subscription = Subscription::query()->create(['merchant_id' => $customer->merchant_id, 'customer_id' => $customer->id, 'billing_cycle' => $pricing->billingCycle, 'currency' => $pricing->currency, 'starts_at' => $startsAt, 'cycle_starts_at' => $period->start, 'cycle_ends_at' => $period->end]);
        $subscription->segments()->create($pricing->snapshot() + ['starts_at' => $startsAt]);

        return $subscription->load('segments');
    }

    public function segmentAt(Subscription $subscription, CarbonImmutable $occurredAt): ?SubscriptionSegment
    {
        return $subscription->segments()->where('starts_at', '<=', $occurredAt)->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $occurredAt))->orderByDesc('starts_at')->first();
    }

    public function openSegment(Subscription $subscription): SubscriptionSegment
    {
        return $subscription->segments()->whereNull('ends_at')->orderByDesc('starts_at')->lockForUpdate()->firstOrFail();
    }

    public function replaceSegment(Subscription $subscription, SubscriptionSegment $old, PricingDTO $pricing, CarbonImmutable $effectiveAt): void
    {
        $old->update(['ends_at' => $effectiveAt]);
        $subscription->segments()->create($pricing->snapshot() + ['starts_at' => $effectiveAt]);
    }

    public function advanceCycle(Subscription $subscription, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $subscription->update(['cycle_starts_at' => $start, 'cycle_ends_at' => $end]);
    }

    public function forMerchant(int $merchantId, int $subscriptionId): Subscription
    {
        return Subscription::query()->where('merchant_id', $merchantId)->with('segments')->findOrFail($subscriptionId);
    }

    public function currentForMerchant(int $merchantId, CarbonImmutable $asOf): LazyCollection
    {
        return Subscription::query()->where('merchant_id', $merchantId)->where('starts_at', '<=', $asOf)->with(['segments' => fn ($query) => $query->where('starts_at', '<=', $asOf)->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $asOf->startOfYear()))])->lazyById(100);
    }

    public function eachDue(CarbonImmutable $cutoff, callable $callback): void
    {
        Subscription::query()->where('cycle_ends_at', '<=', $cutoff)->select(['id', 'merchant_id', 'cycle_starts_at'])->chunkById(100, function ($subscriptions) use ($callback): void {
            foreach ($subscriptions as $subscription) {
                $callback($subscription);
            }
        });
    }
}
