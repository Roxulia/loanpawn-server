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

    public float $excludedDebtTotal;

    public float $totalAmountToPay;

    /**
     * @var InterestPaymentHistoryItem[]
     */
    public array $interestPayments;

    public array $interestRows;

    public array $interestRowVersions;

    /**
     * @var TenantDebtDetail[]
     */
    public array $debts;

    /**
     * @var TenantDebtDetail[]
     */
    public array $excludedDebts;

    /**
     * @var PawnCollateralItemDetail[]
     */
    public array $collateralItems;

    public static function fromValues(
        PawnLoanContractSlip $slip,
        float $calculatedInterest,
        float $totalDebt,
        float $excludedDebtTotal,
        array $interestPayments,
        array $debts,
        array $excludedDebts,
        array $collateralItems,
        int $interestPage = 1,
        int $interestPerPage = 5,
    ): self {
        $loanAmount = (float) $slip->loan_amount;
        $result = new self;
        $result->slip = LoanContractSlipDetail::fromModel($slip);
        $result->customer = $slip->customer !== null ? TenantCustomerDetail::fromModel($slip->customer) : null;
        $result->loanAmount = $loanAmount;
        $result->calculatedInterest = $calculatedInterest;
        $result->totalDebt = $totalDebt;
        $result->excludedDebtTotal = $excludedDebtTotal;
        $result->totalAmountToPay = $loanAmount + $calculatedInterest + $totalDebt;
        $result->interestPayments = $interestPayments;
        $totalInterestRows = count($interestPayments);
        $lastInterestPage = max(1, (int) ceil($totalInterestRows / $interestPerPage));
        $currentInterestPage = min(max(1, $interestPage), $lastInterestPage);
        $result->interestRows = [
            'items' => array_slice($interestPayments, ($currentInterestPage - 1) * $interestPerPage, $interestPerPage),
            'current_page' => $currentInterestPage, 'last_page' => $lastInterestPage,
            'per_page' => $interestPerPage, 'total' => $totalInterestRows,
        ];
        $result->interestRowVersions = array_map(
            fn (InterestPaymentHistoryItem $row): array => ['id' => $row->id, 'update_key' => $row->updateKey],
            $interestPayments,
        );
        $result->debts = array_map(
            fn (TenantDebt|TenantDebtDetail $debt): TenantDebtDetail => $debt instanceof TenantDebtDetail ? $debt : TenantDebtDetail::fromModel($debt),
            $debts
        );
        $result->excludedDebts = array_map(
            fn (TenantDebt|TenantDebtDetail $debt): TenantDebtDetail => $debt instanceof TenantDebtDetail ? $debt : TenantDebtDetail::fromModel($debt),
            $excludedDebts
        );
        $result->collateralItems = $collateralItems;

        return $result;
    }
}
