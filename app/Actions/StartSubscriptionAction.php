<?php

namespace App\Actions;

use App\Exceptions\BillingConflictException;
use App\Models\Subscription;
use App\Repositories\Contracts\CustomerRepository;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Services\PlanPricingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class StartSubscriptionAction
{
    public function __construct(private CustomerRepository $customers, private SubscriptionRepositoryInterface $subscriptions, private PlanPricingService $pricing) {}

    public function handle(int $merchantId, int $customerId, int $planId, CarbonImmutable $startsAt): Subscription
    {
        if ($startsAt > CarbonImmutable::now('UTC')) {
            throw new BillingConflictException('Subscriptions cannot start in the future.');
        }
        $pricing = $this->pricing->get($merchantId, $planId);

        return DB::transaction(function () use ($merchantId, $customerId, $pricing, $startsAt): Subscription {
            $customer = $this->customers->lockForMerchantOrFail($merchantId, $customerId);

            return $this->subscriptions->start($customer, $pricing, $startsAt);
        }, 5);
    }
}
