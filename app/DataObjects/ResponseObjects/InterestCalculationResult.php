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

    /**
     * @param  InterestBreakDown[]  $interestBreakdown
     */
    public static function fromValues(
        string $slipNo,
        int $slipUpdateKey,
        int $accountId,
        string $currentDate,
        array $interestBreakdown
    ): self {
        $result = new self;
        $result->slipNo = $slipNo;
        $result->slipUpdateKey = $slipUpdateKey;
        $result->accountId = $accountId;
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
