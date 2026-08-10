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
        public string $openRate,
        public string $highRate,
        public string $lowRate,
        public string $closeRate,
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
            openRate: $summary->open_rate,
            highRate: $summary->high_rate,
            lowRate: $summary->low_rate,
            closeRate: $summary->close_rate,
            entryCount: $summary->entry_count,
            calculatedAt: $summary->calculated_at?->toIso8601String(),
            source: $summary->tenant_id === null ? 'PLATFORM' : 'TENANT',
        );
    }
}
