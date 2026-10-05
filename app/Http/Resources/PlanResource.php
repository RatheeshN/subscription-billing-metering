<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'merchant_id' => $this->merchant_id, 'name' => $this->name, 'currency' => $this->currency, 'billing_cycle' => $this->billing_cycle->value, 'base_price_minor' => $this->base_price_minor, 'included_usage_units' => $this->included_usage_units, 'overage_rate_micros' => $this->overage_rate_micros];
    }
}
