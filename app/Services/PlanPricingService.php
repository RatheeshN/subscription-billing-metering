<?php

namespace App\Services;

use App\DTOs\PricingDTO;
use App\Exceptions\BillingConflictException;
use App\Models\Plan;
use App\Repositories\Contracts\PlanRepositoryInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PlanPricingService
{
    public function __construct(private PlanRepositoryInterface $plans) {}

    public static function cacheKey(int $merchantId, int $planId): string
    {
        return "merchant:{$merchantId}:plan:{$planId}:pricing";
    }

    public function get(int $merchantId, int $planId): PricingDTO
    {
        $key = self::cacheKey($merchantId, $planId);

        return Cache::lock($key.':lock', 10)->block(5, function () use ($key, $merchantId, $planId): PricingDTO {
            $load = fn () => PricingDTO::fromPlan($this->plans->pricingForMerchant($merchantId, $planId))->toCacheArray();
            $values = Cache::remember($key, (int) config('billing.plan_cache_ttl'), $load);
            if (! is_array($values)) {
                Cache::forget($key);
                $values = Cache::remember($key, (int) config('billing.plan_cache_ttl'), $load);
            }

            return PricingDTO::fromCacheArray($values);
        });
    }

    /** @param array<string, int|string> $attributes */
    public function save(int $merchantId, ?int $planId, array $attributes): Plan
    {
        $save = function () use ($merchantId, $planId, $attributes): Plan {
            try {
                return DB::transaction(fn () => $this->plans->savePricing($merchantId, $planId, $attributes), 5);
            } catch (UniqueConstraintViolationException $exception) {
                throw new BillingConflictException('A plan with this name already exists for the merchant.', previous: $exception);
            }
        };
        if ($planId === null) {
            return $save();
        }
        $key = self::cacheKey($merchantId, $planId);

        return Cache::lock($key.':lock', 10)->block(5, $save);
    }
}
