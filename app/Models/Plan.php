<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['merchant_id', 'name', 'currency', 'billing_cycle', 'base_price_minor', 'included_usage_units', 'overage_rate_micros'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'base_price_minor' => 'integer',
            'included_usage_units' => 'integer',
            'overage_rate_micros' => 'integer',
        ];
    }
}
