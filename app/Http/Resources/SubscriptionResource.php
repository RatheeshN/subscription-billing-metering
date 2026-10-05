<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'customer_id' => $this->customer_id, 'currency' => $this->currency, 'billing_cycle' => $this->billing_cycle->value, 'starts_at' => $this->starts_at->toIso8601String(), 'cycle_starts_at' => $this->cycle_starts_at->toIso8601String(), 'cycle_ends_at' => $this->cycle_ends_at->toIso8601String(), 'segments' => $this->whenLoaded('segments')];
    }
}
