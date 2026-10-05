<?php

namespace App\Models;

use Database\Factories\InvoiceLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['invoice_id', 'subscription_segment_id', 'description', 'starts_at', 'ends_at', 'duration_seconds', 'cycle_seconds', 'base_price_minor', 'overage_rate_micros', 'included_units', 'usage_units', 'overage_units', 'base_total_minor', 'overage_total_minor', 'total_minor'])]
class InvoiceLine extends Model
{
    /** @use HasFactory<InvoiceLineFactory> */
    use HasFactory;

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function subscriptionSegment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionSegment::class);
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'duration_seconds' => 'integer',
            'cycle_seconds' => 'integer',
            'base_price_minor' => 'integer',
            'overage_rate_micros' => 'integer',
            'included_units' => 'integer',
            'usage_units' => 'integer',
            'overage_units' => 'integer',
            'base_total_minor' => 'integer',
            'overage_total_minor' => 'integer',
            'total_minor' => 'integer',
        ];
    }
}
