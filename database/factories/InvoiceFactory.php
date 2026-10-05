<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Invoice> */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        return ['subscription_id' => Subscription::factory(), 'merchant_id' => fn (array $attributes) => Subscription::query()->findOrFail($attributes['subscription_id'])->merchant_id, 'customer_id' => fn (array $attributes) => Subscription::query()->findOrFail($attributes['subscription_id'])->customer_id, 'cycle_starts_at' => '2026-09-01 00:00:00', 'cycle_ends_at' => '2026-10-01 00:00:00', 'currency' => 'INR', 'base_total_minor' => 10000, 'overage_total_minor' => 0, 'total_minor' => 10000];
    }
}
