<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class StoreExchangeRatePairRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['base_currency_code' => ['required', 'string', 'max:12'], 'quote_currency_code' => ['required', 'string', 'max:12', 'different:base_currency_code'], 'is_active' => ['sometimes', 'boolean']];
    }
}
