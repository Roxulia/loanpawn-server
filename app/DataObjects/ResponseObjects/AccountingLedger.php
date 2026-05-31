<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use Carbon\Carbon;

class AccountingLedger extends BaseDataObject
{
    /**
     * @param LedgerEntry[] $entries
     */
    public function __construct(
        public array $entries,
        public Carbon $startDate,
        public Carbon $endDate,
        public ?string $tenantName,
        public float $openingBalance,
        public int $currentPage = 1,
        public int $lastPage = 1,
        public int $perPage = 15,
        public int $total = 0,
    ) {
    }

    public function toArray(): array
    {
        $data = parent::toArray();
        $data['total_debit'] = array_reduce($this->entries, fn (float $total, LedgerEntry $entry) => $total + $entry->debit, 0.0);
        $data['total_credit'] = array_reduce($this->entries, fn (float $total, LedgerEntry $entry) => $total + $entry->credit, 0.0);
        $data['final_balance'] = count($this->entries) > 0
            ? $this->entries[array_key_last($this->entries)]->balance
            : $this->openingBalance;

        return $data;
    }
}
