<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class CorrectExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['rate' => ['required', 'decimal:0,12', 'gt:0'], 'reason' => ['required', 'string', 'max:1000']];
    }
}
