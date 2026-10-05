<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\BillingCycle;
use App\Models\Plan;

final readonly class PricingDTO
{
    public function __construct(
        public int $planId,
        public string $name,
        public string $currency,
        public BillingCycle $billingCycle,
        public int $basePriceMinor,
        public int $includedUsageUnits,
        public int $overageRateMicros,
    ) {}

    public static function fromPlan(Plan $plan): self
    {
        return new self($plan->id, $plan->name, $plan->currency, $plan->billing_cycle, $plan->base_price_minor, $plan->included_usage_units, $plan->overage_rate_micros);
    }

    /** @return array{plan_id: int, plan_name: string, base_price_minor: int, included_usage_units: int, overage_rate_micros: int} */
    public function snapshot(): array
    {
        return ['plan_id' => $this->planId, 'plan_name' => $this->name, 'base_price_minor' => $this->basePriceMinor, 'included_usage_units' => $this->includedUsageUnits, 'overage_rate_micros' => $this->overageRateMicros];
    }

    /** @return array<string, int|string> */
    public function toCacheArray(): array
    {
        return $this->snapshot() + ['name' => $this->name, 'currency' => $this->currency, 'billing_cycle' => $this->billingCycle->value];
    }

    /** @param array<string, int|string> $values */
    public static function fromCacheArray(array $values): self
    {
        return new self((int) $values['plan_id'], (string) $values['name'], (string) $values['currency'], BillingCycle::from((string) $values['billing_cycle']), (int) $values['base_price_minor'], (int) $values['included_usage_units'], (int) $values['overage_rate_micros']);
    }
}
