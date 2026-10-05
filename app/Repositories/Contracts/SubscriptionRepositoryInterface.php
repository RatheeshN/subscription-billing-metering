<?php

namespace App\Repositories\Contracts;

use App\DTOs\PricingDTO;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\SubscriptionSegment;
use Carbon\CarbonImmutable;
use Illuminate\Support\LazyCollection;

interface SubscriptionRepositoryInterface
{
    public function lockForCustomer(int $merchantId, int $customerId): Subscription;

    public function lockForMerchant(int $merchantId, int $subscriptionId): Subscription;

    public function start(Customer $customer, PricingDTO $pricing, CarbonImmutable $startsAt): Subscription;

    public function segmentAt(Subscription $subscription, CarbonImmutable $occurredAt): ?SubscriptionSegment;

    public function openSegment(Subscription $subscription): SubscriptionSegment;

    public function replaceSegment(Subscription $subscription, SubscriptionSegment $old, PricingDTO $pricing, CarbonImmutable $effectiveAt): void;

    public function advanceCycle(Subscription $subscription, CarbonImmutable $start, CarbonImmutable $end): void;

    public function forMerchant(int $merchantId, int $subscriptionId): Subscription;

    /** @return LazyCollection<int, Subscription> */
    public function currentForMerchant(int $merchantId, CarbonImmutable $asOf): LazyCollection;

    public function eachDue(CarbonImmutable $cutoff, callable $callback): void;
}
