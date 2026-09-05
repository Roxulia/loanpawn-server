<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\PawnModule\PawnInterestPayment;

class InterestBreakDown extends BaseDataObject
{
    public int $id;

    public int $updateKey;

    public ?int $createdAccountId;

    public ?int $acceptAccountId;

    public float $interestAmount;

    public ?string $startPeriodAt;

    public ?string $endPeriodAt;

    public ?string $periodTimezone;

    public static function fromValues(
        int $id,
        int $updateKey,
        float $interestAmount,
        ?string $startPeriodAt = null,
        ?string $endPeriodAt = null,
        ?int $createdAccountId = null,
        ?int $acceptAccountId = null,
        ?string $periodTimezone = null,
    ): self {
        $breakDown = new self;
        $breakDown->id = $id;
        $breakDown->updateKey = $updateKey;
        $breakDown->interestAmount = $interestAmount;
        $breakDown->startPeriodAt = $startPeriodAt;
        $breakDown->endPeriodAt = $endPeriodAt;
        $breakDown->createdAccountId = $createdAccountId;
        $breakDown->acceptAccountId = $acceptAccountId;
        $breakDown->periodTimezone = $periodTimezone;

        return $breakDown;
    }

    public static function fromModel(PawnInterestPayment $payment): self
    {
        return self::fromValues(
            id: $payment->id,
            updateKey: (int) $payment->update_key,
            interestAmount: (float) $payment->calculated_interest,
            startPeriodAt: $payment->start_period_at?->toISOString(),
            endPeriodAt: $payment->end_period_at?->toISOString(),
            createdAccountId: $payment->created_account_id,
            acceptAccountId: $payment->accept_account_id,
            periodTimezone: $payment->period_timezone,
        );
    }
}
