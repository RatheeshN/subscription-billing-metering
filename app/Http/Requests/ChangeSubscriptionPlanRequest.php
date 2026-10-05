<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangeSubscriptionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->has('merchant_id');
    }

    public function rules(): array
    {
        return ['plan_id' => ['required', 'integer', 'min:1']];
    }
}
