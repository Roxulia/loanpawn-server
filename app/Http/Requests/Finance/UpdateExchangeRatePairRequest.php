<?php

namespace App\Http\Requests\Finance;

class UpdateExchangeRatePairRequest extends StoreExchangeRatePairRequest
{
    public function rules(): array
    {
        return parent::rules() + ['update_key' => ['required', 'integer', 'min:0']];
    }
}
