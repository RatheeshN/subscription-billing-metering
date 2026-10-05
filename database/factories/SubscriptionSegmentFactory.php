<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionSegment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubscriptionSegment> */
class SubscriptionSegmentFactory extends Factory
{
    public function definition(): array
    {
        return ['subscription_id' => Subscription::factory(), 'plan_id' => fn (array $attributes) => Plan::factory()->create(['merchant_id' => Subscription::query()->findOrFail($attributes['subscription_id'])->merchant_id])->id, 'plan_name' => 'Standard', 'base_price_minor' => 10000, 'included_usage_units' => 100, 'overage_rate_micros' => 10000, 'starts_at' => fn (array $attributes) => Subscription::query()->findOrFail($attributes['subscription_id'])->starts_at, 'ends_at' => null];
    }

    public function withPricing(Plan $plan): static
    {
        return $this->state(fn () => ['plan_id' => $plan->id, 'plan_name' => $plan->name, 'base_price_minor' => $plan->base_price_minor, 'included_usage_units' => $plan->included_usage_units, 'overage_rate_micros' => $plan->overage_rate_micros]);
    }
}
