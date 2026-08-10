<?php

namespace App\Http\Requests\Finance;

class UpdateCurrencyRequest extends StoreCurrencyRequest
{
    public function rules(): array
    {
        return parent::rules() + ['update_key' => ['required', 'integer', 'min:0']];
    }
}
