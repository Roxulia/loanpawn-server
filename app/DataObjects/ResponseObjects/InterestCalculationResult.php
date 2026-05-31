<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class InterestCalculationResult extends BaseDataObject
{
    public string $slipNo;
    public int $slipUpdateKey;
    public string $currentDate;
    public float $totalInterestAmount;
    /**
     * @var InterestBreakDown[]
     */
    public array $interestBreakdown;

    /**
     * @param InterestBreakDown[] $interestBreakdown
     */
    public static function fromValues(
        string $slipNo,
        int $slipUpdateKey,
        string $currentDate,
        array $interestBreakdown
    ): self {
        $result = new self();
        $result->slipNo = $slipNo;
        $result->slipUpdateKey = $slipUpdateKey;
        $result->currentDate = $currentDate;
        $result->interestBreakdown = $interestBreakdown;
        $result->totalInterestAmount = array_reduce(
            $interestBreakdown,
            fn (float $total, InterestBreakDown $row): float => $total + $row->interestAmount,
            0.0
        );

        return $result;
    }
}
