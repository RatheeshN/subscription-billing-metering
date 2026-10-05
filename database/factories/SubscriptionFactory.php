<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Models\Customer;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subscription> */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return ['customer_id' => Customer::factory(), 'merchant_id' => fn (array $attributes) => Customer::query()->findOrFail($attributes['customer_id'])->merchant_id, 'billing_cycle' => BillingCycle::Monthly, 'currency' => 'INR', 'starts_at' => '2026-09-01 00:00:00', 'cycle_starts_at' => '2026-09-01 00:00:00', 'cycle_ends_at' => '2026-10-01 00:00:00'];
    }
}
