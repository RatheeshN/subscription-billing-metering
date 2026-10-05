<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DailyUsageAggregateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['merchant_id', 'customer_id', 'subscription_segment_id', 'usage_date', 'units'])]
class DailyUsageAggregate extends Model
{
    /** @use HasFactory<DailyUsageAggregateFactory> */
    use HasFactory;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function subscriptionSegment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionSegment::class);
    }

    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'usage_date' => 'immutable_date',
        ];
    }

    protected function usageDate(): Attribute
    {
        return Attribute::make(
            set: fn (\DateTimeInterface|string $value): string => CarbonImmutable::parse($value)->toDateString(),
        );
    }
}
