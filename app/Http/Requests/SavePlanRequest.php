<?php

namespace App\Http\Requests;

use App\Enums\BillingCycle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->has('merchant_id');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('plans')->where('merchant_id', $this->attributes->get('merchant_id'))->ignore($this->route('plan'))],
            'currency' => ['required', Rule::in(['INR', 'USD', 'EUR', 'GBP'])],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'base_price_minor' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'included_usage_units' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'overage_rate_micros' => ['required', 'integer', 'min:0', 'max:1000000000'],
        ];
    }
}
