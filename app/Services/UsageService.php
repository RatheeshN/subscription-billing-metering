<?php

namespace App\Services;

use App\Models\UsageEvent;
use App\Repositories\Contracts\CustomerRepository;
use App\Repositories\Contracts\UsageEventRepository;
use Carbon\CarbonImmutable;
use DomainException;
use InvalidArgumentException;

class UsageService
{
    public function __construct(
        private CustomerRepository $customers,
        private UsageEventRepository $usageEvents,
    ) {}

    /** Merchant IDs must come from an authorized tenant context in the caller. */
    public function record(int $merchantId, int $customerId, string $idempotencyKey, int $units, CarbonImmutable $usageDate): UsageEvent
    {
        if ($units < 1 || $units > 2147483647) {
            throw new InvalidArgumentException('Usage units must be between 1 and 2147483647.');
        }

        if (trim($idempotencyKey) === '' || mb_strlen($idempotencyKey) > 100) {
            throw new InvalidArgumentException('An idempotency key of 1 to 100 characters is required.');
        }

        $customer = $this->customers->findForMerchantOrFail($merchantId, $customerId);
        $event = $this->usageEvents->record($customer, $idempotencyKey, $units, $usageDate->toDateString());

        if ($event->units !== $units || $event->usage_date->toDateString() !== $usageDate->toDateString()) {
            throw new DomainException('The idempotency key was already used with different usage data.');
        }

        return $event;
    }

    public function totalUnits(int $merchantId, int $customerId, CarbonImmutable $startDate, CarbonImmutable $endDate): int
    {
        if ($startDate->toDateString() >= $endDate->toDateString()) {
            throw new InvalidArgumentException('The end date must be after the start date.');
        }

        $customer = $this->customers->findForMerchantOrFail($merchantId, $customerId);

        return $this->usageEvents->totalUnits($customer, $startDate->toDateString(), $endDate->toDateString());
    }
}
