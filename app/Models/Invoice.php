<?php

namespace App\Models;

use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['merchant_id', 'customer_id', 'subscription_id', 'cycle_starts_at', 'cycle_ends_at', 'currency', 'base_total_minor', 'overage_total_minor', 'total_minor'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    protected function casts(): array
    {
        return [
            'cycle_starts_at' => 'immutable_datetime',
            'cycle_ends_at' => 'immutable_datetime',
            'base_total_minor' => 'integer',
            'overage_total_minor' => 'integer',
            'total_minor' => 'integer',
        ];
    }
}
