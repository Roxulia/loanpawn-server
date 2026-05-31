<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\TenantDebt;
use App\Models\PawnModule\PawnLoanContractSlip;

class PawnRedemptionResult extends BaseDataObject
{
    public LoanContractSlipDetail $slip;
    public ?TenantCustomerDetail $customer;
    public float $loanAmount;
    public float $calculatedInterest;
    public float $totalDebt;
    public float $totalAmountToPay;
    /**
     * @var InterestPaymentHistoryItem[]
     */
    public array $interestPayments;
    /**
     * @var TenantDebtDetail[]
     */
    public array $debts;
    /**
     * @var PawnCollateralItemDetail[]
     */
    public array $collateralItems;

    public static function fromValues(
        PawnLoanContractSlip $slip,
        float $calculatedInterest,
        float $totalDebt,
        array $interestPayments,
        array $debts,
        array $collateralItems
    ): self {
        $loanAmount = (float) $slip->loan_amount;
        $result = new self();
        $result->slip = LoanContractSlipDetail::fromModel($slip);
        $result->customer = $slip->customer !== null ? TenantCustomerDetail::fromModel($slip->customer) : null;
        $result->loanAmount = $loanAmount;
        $result->calculatedInterest = $calculatedInterest;
        $result->totalDebt = $totalDebt;
        $result->totalAmountToPay = $loanAmount + $calculatedInterest + $totalDebt;
        $result->interestPayments = $interestPayments;
        $result->debts = array_map(
            fn (TenantDebt|TenantDebtDetail $debt): TenantDebtDetail => $debt instanceof TenantDebtDetail ? $debt : TenantDebtDetail::fromModel($debt),
            $debts
        );
        $result->collateralItems = $collateralItems;
        return $result;
    }
}
