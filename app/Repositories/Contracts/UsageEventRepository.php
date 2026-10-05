<?php

namespace App\Repositories\Contracts;

use App\Models\Customer;
use App\Models\UsageEvent;

interface UsageEventRepository
{
    /** Idempotency keys are unique per customer. Returns the existing event on retry. */
    public function record(Customer $customer, string $idempotencyKey, int $units, string $usageDate): UsageEvent;

    /** The date range includes the start date and excludes the end date. */
    public function totalUnits(Customer $customer, string $startDate, string $endDate): int;
}
