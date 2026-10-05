<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UsageEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'customer_id' => $this->customer_id, 'subscription_segment_id' => $this->subscription_segment_id, 'units' => $this->units, 'occurred_at' => $this->occurred_at?->toIso8601String(), 'idempotency_key' => $this->idempotency_key];
    }
}
