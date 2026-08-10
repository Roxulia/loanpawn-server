<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class StoreExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['pair_code' => ['required', 'string', 'max:30'], 'rate' => ['required', 'decimal:0,12', 'gt:0'], 'observed_at' => ['nullable', 'date'], 'idempotency_key' => ['nullable', 'string', 'max:120']];
    }
}
