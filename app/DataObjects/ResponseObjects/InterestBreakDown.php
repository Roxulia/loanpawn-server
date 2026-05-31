<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\PawnModule\PawnInterestPayment;

class InterestBreakDown extends BaseDataObject
{
    public int $id;
    public int $updateKey;
    public float $interestAmount;
    public ?string $startDate;
    public ?string $endDate;

    public static function fromValues(
        int $id,
        int $updateKey,
        float $interestAmount,
        ?string $startDate = null,
        ?string $endDate = null,
    ): self {
        $breakDown = new self();
        $breakDown->id = $id;
        $breakDown->updateKey = $updateKey;
        $breakDown->interestAmount = $interestAmount;
        $breakDown->startDate = $startDate;
        $breakDown->endDate = $endDate;

        return $breakDown;
    }

    public static function fromModel(PawnInterestPayment $payment): self
    {
        return self::fromValues(
            id: $payment->id,
            updateKey: (int) $payment->update_key,
            interestAmount: (float) $payment->calculated_interest,
            startDate: $payment->start_period?->toDateString(),
            endDate: $payment->end_period?->toDateString(),
        );
    }
}
