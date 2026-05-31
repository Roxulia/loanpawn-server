<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\PawnModule\PawnInterestPayment;

class InterestPaymentHistoryItem extends BaseDataObject
{
    public int $id;
    public int $updateKey;
    public ?string $slipNo;
    public ?string $startDate;
    public ?string $endDate;
    public float $interestAmount;
    public float $paymentAmount;
    public float $changeAmount;
    public ?string $paymentDate;
    public bool $isPaid;
    public ?string $notes;

    public static function fromModel(PawnInterestPayment $payment): self
    {
        $item = new self();
        $item->id = $payment->id;
        $item->updateKey = (int) $payment->update_key;
        $item->slipNo = $payment->slip?->slip_no;
        $item->startDate = $payment->start_period?->toDateString();
        $item->endDate = $payment->end_period?->toDateString();
        $item->interestAmount = (float) $payment->calculated_interest;
        $item->paymentAmount = (float) $payment->payment_amount;
        $item->changeAmount = (float) $payment->change_amount;
        $item->paymentDate = $payment->payment_date?->toDateString();
        $item->isPaid = (bool) $payment->is_paid;
        $item->notes = $payment->notes;

        return $item;
    }
}
