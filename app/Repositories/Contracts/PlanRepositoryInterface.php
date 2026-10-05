<?php

namespace App\Repositories\Contracts;

use App\Models\Plan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PlanRepositoryInterface
{
    public function pricingForMerchant(int $merchantId, int $planId): Plan;

    /** @param array<string, int|string> $attributes */
    public function savePricing(int $merchantId, ?int $planId, array $attributes): Plan;

    public function listForMerchant(int $merchantId): LengthAwarePaginator;
}
