<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'min:3', 'max:12', 'regex:/^[A-Za-z0-9]+$/'], 'name' => ['required', 'string', 'max:120'], 'symbol' => ['nullable', 'string', 'max:12'], 'decimal_precision' => ['required', 'integer', 'min:0', 'max:8'], 'rounding_mode' => ['required', Rule::in(['HALF_UP', 'HALF_DOWN', 'HALF_EVEN', 'UP', 'DOWN'])], 'adjustment_step' => ['nullable', 'decimal:0,8', 'gt:0'], 'is_active' => ['sometimes', 'boolean']];
    }
}
