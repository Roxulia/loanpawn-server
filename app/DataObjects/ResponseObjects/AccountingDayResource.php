<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\TenantAccountingDay;

class AccountingDayResource extends BaseDataObject
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public string $businessDate,
        public string $timezone,
        public string $status,
        public ?string $openedAt,
        public ?int $openedBy,
        public ?string $openingSource,
        public ?string $closedAt,
        public ?string $effectiveClosedAt,
        public ?int $closedBy,
        public ?string $closingSource,
        public array $closeMetadata,
        public array $summaries,
    ) {}

    public static function fromModel(TenantAccountingDay $day): self
    {
        $day->loadMissing('summaries.currency');

        return new self(
            id: $day->id,
            tenantId: $day->tenant_id,
            businessDate: $day->business_date->toDateString(),
            timezone: $day->timezone,
            status: $day->status->value,
            openedAt: $day->opened_at?->toISOString(),
            openedBy: $day->opened_by,
            openingSource: $day->opening_source?->value,
            closedAt: $day->closed_at?->toISOString(),
            effectiveClosedAt: $day->effective_closed_at?->toISOString(),
            closedBy: $day->closed_by,
            closingSource: $day->closing_source?->value,
            closeMetadata: $day->close_metadata ?? [],
            summaries: $day->summaries->map(fn ($summary): array => [
                'currency_id' => $summary->currency_id,
                'currency_code' => $summary->currency?->code,
                'opening_balance' => $summary->opening_balance,
                'total_incoming' => $summary->total_incoming,
                'total_outgoing' => $summary->total_outgoing,
                'closing_balance' => $summary->closing_balance,
                'revenue' => $summary->revenue,
                'expense' => $summary->expense,
                'profit' => $summary->profit,
                'category_totals' => $summary->category_totals ?? [],
            ])->all(),
        );
    }
}
