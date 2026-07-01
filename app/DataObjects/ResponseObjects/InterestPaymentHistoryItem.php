<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\PawnModule\PawnInterestPayment;

class InterestPaymentHistoryItem extends BaseDataObject
{
    public int $id;
    public int $updateKey;
    public ?string $slipNo;
    public ?string $startPeriodAt;
    public ?string $endPeriodAt;
    public float $interestAmount;
    public float $paymentAmount;
    public float $changeAmount;
    public ?string $paymentAt;
    public bool $isPaid;
    public ?string $notes;

    public static function fromModel(PawnInterestPayment $payment): self
    {
        $item = new self();
        $item->id = $payment->id;
        $item->updateKey = (int) $payment->update_key;
        $item->slipNo = $payment->slip?->slip_no;
        $item->startPeriodAt = $payment->start_period_at?->toISOString();
        $item->endPeriodAt = $payment->end_period_at?->toISOString();
        $item->interestAmount = (float) $payment->calculated_interest;
        $item->paymentAmount = (float) $payment->payment_amount;
        $item->changeAmount = (float) $payment->change_amount;
        $item->paymentAt = $payment->payment_at?->toISOString();
        $item->isPaid = (bool) $payment->is_paid;
        $item->notes = $payment->notes;

        return $item;
    }
}
