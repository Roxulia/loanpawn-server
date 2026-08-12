<?php

namespace App\Services\TenantModule\Accounting;

use App\DataObjects\RequestObjects\FinancialAccountTransactionCreate;
use App\Enums\FinancialAccountTransactionType;
use App\Exceptions\InvalidTenantRequest;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountTransaction;
use App\Repository\Accounting\FinancialAccountTransactionRepository;

class FinancialAccountTransactionService
{
    public function __construct(private FinancialAccountTransactionRepository $repository) {}

    public function record(FinancialAccountTransactionCreate $request): FinancialAccountTransaction
    {
        if ((int) $request->account->tenant_id !== $request->tenantId || $request->account->is_deleted) {
            throw new InvalidTenantRequest('The financial account is not available for this tenant.');
        }

        if ($request->amount <= 0 || ! in_array($request->direction, ['debit', 'credit'], true)) {
            throw new InvalidTenantRequest('A positive amount and valid transaction direction are required.');
        }

        return $this->repository->create([
            'tenant_id' => $request->tenantId,
            'financial_account_id' => $request->account->id,
            'transaction_type' => $request->transactionType->value,
            'amount' => $request->amount,
            'direction' => $request->direction,
            'reference_number' => $request->referenceNumber,
            'reference_type' => $request->referenceType,
            'note' => $request->note,
            'created_by' => $request->createdBy,
            'related_transaction_id' => $request->relatedTransactionId,
        ]);
    }

    public function recordOpeningBalance(FinancialAccount $account, float $amount, ?int $createdBy): ?FinancialAccountTransaction
    {
        if ($amount <= 0) {
            return null;
        }

        return $this->recordType($account, FinancialAccountTransactionType::OpeningBalance, $amount, 'debit', note: 'Opening balance', createdBy: $createdBy);
    }

    public function recordPawnLoanCreation(FinancialAccount $account, float $amount, ?string $referenceNumber = null, ?string $referenceType = null, ?string $note = null, ?int $createdBy = null, ?int $relatedTransactionId = null): FinancialAccountTransaction
    {
        return $this->recordType($account, FinancialAccountTransactionType::PawnLoanCreation, $amount, 'credit', $referenceNumber, $referenceType, $note, $createdBy, $relatedTransactionId);
    }

    public function recordPawnInterestPayment(FinancialAccount $account, float $amount, ?string $referenceNumber = null, ?string $referenceType = null, ?string $note = null, ?int $createdBy = null, ?int $relatedTransactionId = null): FinancialAccountTransaction
    {
        return $this->recordType($account, FinancialAccountTransactionType::PawnInterestPayment, $amount, 'debit', $referenceNumber, $referenceType, $note, $createdBy, $relatedTransactionId);
    }

    public function recordPawnRedemption(FinancialAccount $account, float $amount, ?string $referenceNumber = null, ?string $referenceType = null, ?string $note = null, ?int $createdBy = null, ?int $relatedTransactionId = null): FinancialAccountTransaction
    {
        return $this->recordType($account, FinancialAccountTransactionType::PawnRedemption, $amount, 'debit', $referenceNumber, $referenceType, $note, $createdBy, $relatedTransactionId);
    }

    public function recordDebtCreation(FinancialAccount $account, float $amount, ?string $referenceNumber = null, ?string $referenceType = null, ?string $note = null, ?int $createdBy = null, ?int $relatedTransactionId = null): FinancialAccountTransaction
    {
        return $this->recordType($account, FinancialAccountTransactionType::DebtCreation, $amount, 'credit', $referenceNumber, $referenceType, $note, $createdBy, $relatedTransactionId);
    }

    public function recordDebtPayment(FinancialAccount $account, float $amount, ?string $referenceNumber = null, ?string $referenceType = null, ?string $note = null, ?int $createdBy = null, ?int $relatedTransactionId = null): FinancialAccountTransaction
    {
        return $this->recordType($account, FinancialAccountTransactionType::DebtPayment, $amount, 'debit', $referenceNumber, $referenceType, $note, $createdBy, $relatedTransactionId);
    }

    public function recordBusinessLoanReceipt(FinancialAccount $account, float $amount, ?string $referenceNumber = null, ?string $referenceType = null, ?string $note = null, ?int $createdBy = null, ?int $relatedTransactionId = null): FinancialAccountTransaction
    {
        return $this->recordType($account, FinancialAccountTransactionType::BusinessLoanReceipt, $amount, 'debit', $referenceNumber, $referenceType, $note, $createdBy, $relatedTransactionId);
    }

    public function recordBusinessLoanPayment(FinancialAccount $account, float $amount, ?string $referenceNumber = null, ?string $referenceType = null, ?string $note = null, ?int $createdBy = null, ?int $relatedTransactionId = null): FinancialAccountTransaction
    {
        return $this->recordType($account, FinancialAccountTransactionType::BusinessLoanPayment, $amount, 'credit', $referenceNumber, $referenceType, $note, $createdBy, $relatedTransactionId);
    }

    public function recordExpensePayment(FinancialAccount $account, float $amount, ?string $referenceNumber = null, ?string $referenceType = null, ?string $note = null, ?int $createdBy = null, ?int $relatedTransactionId = null): FinancialAccountTransaction
    {
        return $this->recordType($account, FinancialAccountTransactionType::ExpensePayment, $amount, 'credit', $referenceNumber, $referenceType, $note, $createdBy, $relatedTransactionId);
    }

    public function recordCapitalContribution(FinancialAccount $account, float $amount, ?string $referenceNumber = null, ?string $referenceType = null, ?string $note = null, ?int $createdBy = null, ?int $relatedTransactionId = null): FinancialAccountTransaction
    {
        return $this->recordType($account, FinancialAccountTransactionType::CapitalContribution, $amount, 'debit', $referenceNumber, $referenceType, $note, $createdBy, $relatedTransactionId);
    }

    public function recordCapitalWithdrawal(FinancialAccount $account, float $amount, ?string $referenceNumber = null, ?string $referenceType = null, ?string $note = null, ?int $createdBy = null, ?int $relatedTransactionId = null): FinancialAccountTransaction
    {
        return $this->recordType($account, FinancialAccountTransactionType::CapitalWithdrawal, $amount, 'credit', $referenceNumber, $referenceType, $note, $createdBy, $relatedTransactionId);
    }

    public function recordAccountTransfer(FinancialAccount $account, float $amount, string $direction, ?string $referenceNumber = null, ?string $referenceType = null, ?string $note = null, ?int $createdBy = null, ?int $relatedTransactionId = null): FinancialAccountTransaction
    {
        return $this->recordType($account, FinancialAccountTransactionType::AccountTransfer, $amount, $direction, $referenceNumber, $referenceType, $note, $createdBy, $relatedTransactionId);
    }

    public function recordTransferFee(FinancialAccount $account, float $amount, ?string $referenceNumber = null, ?string $referenceType = null, ?string $note = null, ?int $createdBy = null, ?int $relatedTransactionId = null): FinancialAccountTransaction
    {
        return $this->recordType($account, FinancialAccountTransactionType::TransferFee, $amount, 'credit', $referenceNumber, $referenceType, $note, $createdBy, $relatedTransactionId);
    }

    public function recordAdjustment(FinancialAccount $account, float $amount, string $direction, ?string $referenceNumber = null, ?string $referenceType = null, ?string $note = null, ?int $createdBy = null, ?int $relatedTransactionId = null): FinancialAccountTransaction
    {
        return $this->recordType($account, FinancialAccountTransactionType::Adjustment, $amount, $direction, $referenceNumber, $referenceType, $note, $createdBy, $relatedTransactionId);
    }

    public function recordReversal(FinancialAccount $account, float $amount, string $direction, ?string $referenceNumber = null, ?string $referenceType = null, ?string $note = null, ?int $createdBy = null, ?int $relatedTransactionId = null): FinancialAccountTransaction
    {
        return $this->recordType($account, FinancialAccountTransactionType::Reversal, $amount, $direction, $referenceNumber, $referenceType, $note, $createdBy, $relatedTransactionId);
    }

    private function recordType(
        FinancialAccount $account,
        FinancialAccountTransactionType $transactionType,
        float $amount,
        string $direction,
        ?string $referenceNumber = null,
        ?string $referenceType = null,
        ?string $note = null,
        ?int $createdBy = null,
        ?int $relatedTransactionId = null,
    ): FinancialAccountTransaction {
        return $this->record(new FinancialAccountTransactionCreate(
            tenantId: (int) $account->tenant_id,
            account: $account,
            transactionType: $transactionType,
            amount: $amount,
            direction: $direction,
            referenceNumber: $referenceNumber,
            referenceType: $referenceType,
            note: $note,
            createdBy: $createdBy,
            relatedTransactionId: $relatedTransactionId,
        ));
    }
}
