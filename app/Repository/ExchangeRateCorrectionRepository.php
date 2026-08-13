<?php

namespace App\Repository;

use App\Models\CoreModule\ExchangeRateCorrection;

class ExchangeRateCorrectionRepository
{
    public function create(array $data): ExchangeRateCorrection
    {
        return ExchangeRateCorrection::query()->create($data);
    }
}
