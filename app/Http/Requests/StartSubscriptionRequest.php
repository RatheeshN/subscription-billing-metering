<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->has('merchant_id');
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'min:1'],
            'plan_id' => ['required', 'integer', 'min:1'],
            'starts_at' => ['sometimes', 'required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/', 'before_or_equal:now'],
        ];
    }
}
