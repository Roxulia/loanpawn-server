<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\ExchangeRateEntry;

class ExchangeRateEntryResource extends BaseDataObject
{
    public function __construct(
        public int $id,
        public string $code,
        public ExchangeRatePairResource $pair,
        public string $rate,
        public ?string $effectiveDate,
        public ?string $observedAt,
        public string $source,
        public bool $isVoid,
        public ?string $voidedAt,
        public ?string $voidReason,
        public bool $canCorrect,
        public bool $canVoid,
    ) {}

    public static function fromModel(ExchangeRateEntry $exchangeRateEntry): self
    {
        $canModify = $exchangeRateEntry->tenant_id !== null && ! $exchangeRateEntry->is_void;

        return new self(
            id: $exchangeRateEntry->id,
            code: $exchangeRateEntry->code,
            pair: ExchangeRatePairResource::fromModel($exchangeRateEntry->pair),
            rate: $exchangeRateEntry->rate,
            effectiveDate: $exchangeRateEntry->effective_date?->toDateString(),
            observedAt: $exchangeRateEntry->observed_at?->toIso8601String(),
            source: $exchangeRateEntry->source,
            isVoid: $exchangeRateEntry->is_void,
            voidedAt: $exchangeRateEntry->voided_at?->toIso8601String(),
            voidReason: $exchangeRateEntry->void_reason,
            canCorrect: $canModify,
            canVoid: $canModify,
        );
    }
}
