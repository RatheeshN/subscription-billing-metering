<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\SubscriptionSegment;
use App\Models\UsageEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageEvent>
 */
class UsageEventFactory extends Factory
{
    public function metered(SubscriptionSegment $segment): static
    {
        $subscription = $segment->subscription;

        return $this->state(fn () => [
            'customer_id' => $subscription->customer_id,
            'merchant_id' => $subscription->merchant_id,
            'subscription_segment_id' => $segment->id,
            'occurred_at' => $segment->starts_at,
            'usage_date' => fn (array $attributes) => CarbonImmutable::parse($attributes['occurred_at'])->toDateString(),
        ]);
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'idempotency_key' => fake()->uuid(),
            'units' => 10,
            'usage_date' => '2026-10-01',
        ];
    }
}
