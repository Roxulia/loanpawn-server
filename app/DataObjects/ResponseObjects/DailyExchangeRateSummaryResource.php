<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\DailyExchangeRateSummary;

class DailyExchangeRateSummaryResource extends BaseDataObject
{
    public function __construct(
        public int $id,
        public ?string $rateDate,
        public ExchangeRatePairResource $pair,
        public string $buyingOpen,
        public string $buyingHigh,
        public string $buyingLow,
        public string $buyingClose,
        public string $sellingOpen,
        public string $sellingHigh,
        public string $sellingLow,
        public string $sellingClose,
        public int $entryCount,
        public ?string $calculatedAt,
        public string $source,
    ) {}

    public static function fromModel(DailyExchangeRateSummary $summary): self
    {
        return new self(
            id: $summary->id,
            rateDate: $summary->rate_date?->toDateString(),
            pair: ExchangeRatePairResource::fromModel($summary->pair),
            buyingOpen: $summary->buying_open,
            buyingHigh: $summary->buying_high,
            buyingLow: $summary->buying_low,
            buyingClose: $summary->buying_close,
            sellingOpen: $summary->selling_open,
            sellingHigh: $summary->selling_high,
            sellingLow: $summary->selling_low,
            sellingClose: $summary->selling_close,
            entryCount: $summary->entry_count,
            calculatedAt: $summary->calculated_at?->toIso8601String(),
            source: $summary->tenant_id === null ? 'PLATFORM' : 'TENANT',
        );
    }
}
