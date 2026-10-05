<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'customer_id' => $this->customer_id, 'subscription_id' => $this->subscription_id, 'currency' => $this->currency, 'cycle_starts_at' => $this->cycle_starts_at->toIso8601String(), 'cycle_ends_at' => $this->cycle_ends_at->toIso8601String(), 'base_total_minor' => $this->base_total_minor, 'overage_total_minor' => $this->overage_total_minor, 'total_minor' => $this->total_minor, 'lines' => $this->whenLoaded('lines')];
    }
}
