<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\SubscriptionSegment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InvoiceLine> */
class InvoiceLineFactory extends Factory
{
    public function definition(): array
    {
        return ['invoice_id' => Invoice::factory(), 'subscription_segment_id' => fn (array $attributes) => SubscriptionSegment::factory()->create(['subscription_id' => Invoice::query()->findOrFail($attributes['invoice_id'])->subscription_id])->id, 'description' => 'Standard', 'starts_at' => '2026-09-01 00:00:00', 'ends_at' => '2026-10-01 00:00:00', 'duration_seconds' => 2592000, 'cycle_seconds' => 2592000, 'base_price_minor' => 10000, 'overage_rate_micros' => 10000, 'included_units' => 100, 'usage_units' => 0, 'overage_units' => 0, 'base_total_minor' => 10000, 'overage_total_minor' => 0, 'total_minor' => 10000];
    }
}
