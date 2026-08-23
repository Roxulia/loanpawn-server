<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class HistoricalRateRequirementsResource extends BaseDataObject
{
    public function __construct(
        public int $recalculationId,
        public string $status,
        public array $previousCurrency,
        public array $requestedCurrency,
        public array $requirements,
        public int $currencySettingUpdateKey,
    ) {}
}
