<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class InterestCalculationResult extends BaseDataObject
{
    public string $slipNo;

    public int $slipUpdateKey;

    public int $accountId;

    public string $currentDate;

    public float $totalInterestAmount;

    /**
     * @var InterestBreakDown[]
     */
    public array $interestBreakdown;

    public array $interestRows;

    public array $interestRowVersions;

    /**
     * @param  InterestBreakDown[]  $interestBreakdown
     */
    public static function fromValues(
        string $slipNo,
        int $slipUpdateKey,
        int $accountId,
        string $currentDate,
        array $interestBreakdown,
        int $page = 1,
        int $perPage = 5,
    ): self {
        $result = new self;
        $result->slipNo = $slipNo;
        $result->slipUpdateKey = $slipUpdateKey;
        $result->accountId = $accountId;
        $result->currentDate = $currentDate;
        $result->interestBreakdown = $interestBreakdown;
        $total = count($interestBreakdown);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $currentPage = min(max(1, $page), $lastPage);
        // Keep full row versions for atomic payment validation while paging display data.
        $result->interestRows = [
            'items' => array_slice($interestBreakdown, ($currentPage - 1) * $perPage, $perPage),
            'current_page' => $currentPage, 'last_page' => $lastPage, 'per_page' => $perPage, 'total' => $total,
        ];
        $result->interestRowVersions = array_map(
            fn (InterestBreakDown $row): array => ['id' => $row->id, 'update_key' => $row->updateKey],
            $interestBreakdown,
        );
        $result->totalInterestAmount = array_reduce(
            $interestBreakdown,
            fn (float $total, InterestBreakDown $row): float => $total + $row->interestAmount,
            0.0
        );

        return $result;
    }
}
