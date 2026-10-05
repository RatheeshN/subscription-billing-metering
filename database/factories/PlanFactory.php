<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Models\Merchant;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Plan> */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return ['merchant_id' => Merchant::factory(), 'name' => fake()->unique()->words(3, true), 'currency' => 'INR', 'billing_cycle' => BillingCycle::Monthly, 'base_price_minor' => 10000, 'included_usage_units' => 100, 'overage_rate_micros' => 10000];
    }
}
