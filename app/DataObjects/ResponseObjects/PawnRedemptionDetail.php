<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\PawnModule\PawnRedemption;

class PawnRedemptionDetail extends BaseDataObject
{
    public int $id;
    public int $slipId;
    public string $slipNumber;
    public float $grossAmount;
    public float $netAmount;
    public float $interestAmount;
    public float $receivedAmount;
    public float $changeAmount;
    public ?string $redemptionDate;
    public ?string $notes;
    public ?int $createdBy;
    public int $updateKey;

    public static function fromModel(PawnRedemption $redemption): self
    {
        $detail = new self();
        $detail->id = $redemption->id;
        $detail->slipId = $redemption->slip_id;
        $detail->slipNumber = $redemption->slip_number;
        $detail->grossAmount = (float) $redemption->gross_amount;
        $detail->netAmount = (float) $redemption->net_amount;
        $detail->interestAmount = (float) $redemption->interest_amount;
        $detail->receivedAmount = (float) $redemption->received_amount;
        $detail->changeAmount = (float) $redemption->change_amount;
        $detail->redemptionDate = $redemption->redemption_date?->toDateString();
        $detail->notes = $redemption->notes;
        $detail->createdBy = $redemption->created_by;
        $detail->updateKey = (int) $redemption->update_key;
        return $detail;
    }
}
