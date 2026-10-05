<?php

namespace App\Observers;

use App\Models\Plan;
use App\Services\PlanPricingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PlanObserver
{
    public function saved(Plan $plan): void
    {
        $key = PlanPricingService::cacheKey($plan->merchant_id, $plan->id);
        DB::afterCommit(fn () => Cache::forget($key));
    }

    public function deleted(Plan $plan): void
    {
        $this->saved($plan);
    }
}
