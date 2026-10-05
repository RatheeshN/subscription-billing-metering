<?php

namespace App\Models;

use Database\Factories\SubscriptionSegmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['subscription_id', 'plan_id', 'plan_name', 'base_price_minor', 'included_usage_units', 'overage_rate_micros', 'starts_at', 'ends_at'])]
class SubscriptionSegment extends Model
{
    /** @use HasFactory<SubscriptionSegmentFactory> */
    use HasFactory;

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    protected function casts(): array
    {
        return [
            'base_price_minor' => 'integer',
            'included_usage_units' => 'integer',
            'overage_rate_micros' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }
}
