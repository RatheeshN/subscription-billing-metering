<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['merchant_id', 'customer_id', 'billing_cycle', 'currency', 'starts_at', 'cycle_starts_at', 'cycle_ends_at'])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(SubscriptionSegment::class)->orderBy('starts_at');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'starts_at' => 'immutable_datetime',
            'cycle_starts_at' => 'immutable_datetime',
            'cycle_ends_at' => 'immutable_datetime',
        ];
    }
}
