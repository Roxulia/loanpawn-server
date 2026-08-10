<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\ExchangeRatePair;

class ExchangeRatePairResource extends BaseDataObject
{
    public function __construct(
        public int $id,
        public string $code,
        public string $displayCode,
        public CurrencyResource $baseCurrency,
        public CurrencyResource $quoteCurrency,
        public bool $isDefault,
        public bool $isActive,
        public string $source,
        public bool $canUpdate,
        public bool $canDelete,
        public int $updateKey,
    ) {}

    public static function fromModel(ExchangeRatePair $exchangeRatePair): self
    {
        $isTenantPair = $exchangeRatePair->tenant_id !== null;

        return new self(
            id: $exchangeRatePair->id,
            code: $exchangeRatePair->code,
            displayCode: "{$exchangeRatePair->baseCurrency->code}/{$exchangeRatePair->quoteCurrency->code}",
            baseCurrency: CurrencyResource::fromModel($exchangeRatePair->baseCurrency),
            quoteCurrency: CurrencyResource::fromModel($exchangeRatePair->quoteCurrency),
            isDefault: $exchangeRatePair->is_default,
            isActive: $exchangeRatePair->is_active,
            source: $isTenantPair ? 'TENANT' : 'PLATFORM',
            canUpdate: $isTenantPair,
            canDelete: $isTenantPair,
            updateKey: $exchangeRatePair->update_key,
        );
    }
}
