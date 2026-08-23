<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class ReportingExchangeRateQuoteResource extends BaseDataObject
{
    public function __construct(
        public int $fromCurrencyId,
        public int $toCurrencyId,
        public string $fromCurrencyCode,
        public string $toCurrencyCode,
        public string $businessDate,
        public ?float $multiplier,
        public ?string $direction,
        public ?string $pairCode,
        public ?string $source,
        public bool $requiresManual,
    ) {}
}
