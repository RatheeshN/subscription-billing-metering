<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UsageEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'idempotency_key', 'units', 'usage_date', 'merchant_id', 'subscription_segment_id', 'occurred_at', 'aggregated_at'])]
class UsageEvent extends Model
{
    /** @use HasFactory<UsageEventFactory> */
    use HasFactory;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    protected function casts(): array
    {
        return ['units' => 'integer', 'usage_date' => 'immutable_date', 'occurred_at' => 'immutable_datetime', 'aggregated_at' => 'immutable_datetime'];
    }

    protected function usageDate(): Attribute
    {
        return Attribute::make(
            set: fn (\DateTimeInterface|string $value): string => CarbonImmutable::parse($value)->toDateString(),
        );
    }

    public function subscriptionSegment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionSegment::class);
    }
}
