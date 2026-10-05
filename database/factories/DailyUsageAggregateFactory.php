<?php

namespace Database\Factories;

use App\Models\DailyUsageAggregate;
use App\Models\SubscriptionSegment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DailyUsageAggregate> */
class DailyUsageAggregateFactory extends Factory
{
    public function definition(): array
    {
        return ['subscription_segment_id' => SubscriptionSegment::factory(), 'merchant_id' => fn (array $attributes) => SubscriptionSegment::query()->findOrFail($attributes['subscription_segment_id'])->subscription->merchant_id, 'customer_id' => fn (array $attributes) => SubscriptionSegment::query()->findOrFail($attributes['subscription_segment_id'])->subscription->customer_id, 'usage_date' => '2026-09-01', 'units' => 10];
    }
}
