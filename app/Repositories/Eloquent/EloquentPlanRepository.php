<?php

namespace App\Repositories\Eloquent;

use App\Models\Plan;
use App\Repositories\Contracts\PlanRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentPlanRepository implements PlanRepositoryInterface
{
    public function pricingForMerchant(int $merchantId, int $planId): Plan
    {
        return Plan::query()->where('merchant_id', $merchantId)->findOrFail($planId);
    }

    public function savePricing(int $merchantId, ?int $planId, array $attributes): Plan
    {
        $plan = $planId === null ? new Plan(['merchant_id' => $merchantId]) : $this->pricingForMerchant($merchantId, $planId);
        $plan->fill($attributes)->save();

        return $plan;
    }

    public function listForMerchant(int $merchantId): LengthAwarePaginator
    {
        return Plan::query()->where('merchant_id', $merchantId)->orderBy('id')->paginate(50);
    }
}
