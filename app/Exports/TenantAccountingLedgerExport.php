<?php

namespace App\Exports;

use App\DataObjects\ResponseObjects\LedgerEntry;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class TenantAccountingLedgerExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    /**
     * @param LedgerEntry[] $entries
     */
    public function __construct(
        private array $entries,
        private Carbon $startDate,
        private Carbon $endDate,
        private ?string $tenantName,
        private float $openingBalance,
        private string $currencySymbol = '',
    ) {
    }

    public function collection(): Collection
    {
        return collect([
            [
                'label' => $this->tenantName ?? 'Tenant',
                'description' => sprintf('General ledger from %s to %s', $this->startDate->toDateString(), $this->endDate->toDateString()),
                'balance' => $this->openingBalance,
                'type' => 'summary',
            ],
            ...$this->entries,
        ]);
    }

    public function headings(): array
    {
        return [
            'Date',
            'Description',
            'Reference',
            "Debit ({$this->currencySymbol})",
            "Credit ({$this->currencySymbol})",
            "Balance ({$this->currencySymbol})",
        ];
    }

    /**
     * @param LedgerEntry|array<string, mixed> $row
     */
    public function map($row): array
    {
        if (is_array($row)) {
            return ['', $row['description'], $row['label'], '', '', $row['balance']];
        }

        return [
            $row->createdAt->toDateTimeString(),
            $row->description,
            $this->formatReference($row),
            $row->debit,
            $row->credit,
            $row->balance,
        ];
    }

    public function title(): string
    {
        return 'General Ledger';
    }

    private function formatReference(LedgerEntry $entry): string
    {
        if ($entry->referenceType === null && $entry->referenceId === null) {
            return '';
        }

        return ($entry->referenceLabel ?? 'Reference') . ($entry->referenceId === null ? '' : ' #' . $entry->referenceId);
    }
}
