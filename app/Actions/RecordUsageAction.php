<?php

namespace App\Actions;

use App\DTOs\RecordUsageDTO;
use App\Exceptions\BillingConflictException;
use App\Models\UsageEvent;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Repositories\Contracts\UsageEventRepositoryInterface;
use Illuminate\Support\Facades\DB;

class RecordUsageAction
{
    public function __construct(private UsageEventRepositoryInterface $events, private SubscriptionRepositoryInterface $subscriptions) {}

    public function handle(RecordUsageDTO $usage): UsageEvent
    {
        if ($existing = $this->events->findRetry($usage)) {
            return $this->verifyRetry($existing, $usage);
        }

        return DB::transaction(function () use ($usage): UsageEvent {
            $subscription = $this->subscriptions->lockForCustomer($usage->merchantId, $usage->customerId);
            if ($existing = $this->events->findRetry($usage)) {
                return $this->verifyRetry($existing, $usage);
            }
            if ($usage->occurredAt < $subscription->cycle_starts_at) {
                throw new BillingConflictException('Usage belongs to an already finalized billing cycle.');
            }
            $segment = $this->subscriptions->segmentAt($subscription, $usage->occurredAt);
            if ($segment === null) {
                throw new BillingConflictException('No subscription pricing segment covers the event timestamp.');
            }

            return $this->verifyRetry($this->events->recordUsage($usage, $segment), $usage);
        }, 5);
    }

    private function verifyRetry(UsageEvent $event, RecordUsageDTO $usage): UsageEvent
    {
        if ($event->merchant_id !== $usage->merchantId || $event->units !== $usage->units || $event->occurred_at === null || ! $event->occurred_at->equalTo($usage->occurredAt)) {
            throw new BillingConflictException('The idempotency key was already used with different usage data.');
        }

        return $event;
    }
}
