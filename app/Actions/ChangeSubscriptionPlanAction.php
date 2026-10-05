<?php

namespace App\Actions;

use App\Exceptions\BillingConflictException;
use App\Models\Subscription;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Repositories\Contracts\UsageEventRepositoryInterface;
use App\Services\PlanPricingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ChangeSubscriptionPlanAction
{
    public function __construct(private SubscriptionRepositoryInterface $subscriptions, private UsageEventRepositoryInterface $events, private PlanPricingService $pricing) {}

    public function handle(int $merchantId, int $subscriptionId, int $planId): Subscription
    {
        $pricing = $this->pricing->get($merchantId, $planId);

        return DB::transaction(function () use ($merchantId, $subscriptionId, $pricing): Subscription {
            $subscription = $this->subscriptions->lockForMerchant($merchantId, $subscriptionId);
            $old = $this->subscriptions->openSegment($subscription);
            if ($old->plan_id === $pricing->planId) {
                return $this->subscriptions->forMerchant($merchantId, $subscriptionId);
            }
            if ($subscription->billing_cycle !== $pricing->billingCycle || $subscription->currency !== $pricing->currency) {
                throw new BillingConflictException('Plan changes must keep the same billing cycle and currency.');
            }
            $effectiveAt = CarbonImmutable::now('UTC')->startOfSecond();
            if ($effectiveAt <= $old->starts_at || $this->events->hasUsageAtOrAfter($old, $effectiveAt)) {
                throw new BillingConflictException('A plan change must be after the current segment start and its recorded usage. Retry in the next second.');
            }
            $this->subscriptions->replaceSegment($subscription, $old, $pricing, $effectiveAt);

            return $this->subscriptions->forMerchant($merchantId, $subscriptionId);
        }, 5);
    }
}
