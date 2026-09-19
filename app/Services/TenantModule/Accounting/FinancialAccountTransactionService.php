<?php

namespace App\Services\TenantModule\Accounting;

use App\DataObjects\RequestObjects\FinancialAccountTransactionCreate;
use App\DataObjects\RequestObjects\FinancialAccountTransactionFilter;
use App\DataObjects\ResponseObjects\FinancialAccountTransactionListPage;
use App\Enums\FinancialAccountTransactionType;
use App\Exceptions\InvalidTenantRequest;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountTransaction;
use App\Repository\Accounting\FinancialAccountTransactionRepository;
use Illuminate\Support\Facades\DB;

class FinancialAccountTransactionService
{
    public function __construct(private FinancialAccountTransactionRepository $repository) {}

    public function record(FinancialAccountTransactionCreate $request): FinancialAccountTransaction
    {
        if ((int) $request->account->tenant_id !== $request->tenantId || ($request->account->is_deleted && $request->transactionType !== FinancialAccountTransactionType::Reversal)) {
            throw new InvalidTenantRequest('The financial account is not available for this tenant.');
        }

        if ($request->amount <= 0 || ! in_array($request->direction, ['debit', 'credit'], true)) {
            throw new InvalidTenantRequest('A positive amount and valid transaction direction are required.');
        }

        return DB::transaction(function () use ($request): FinancialAccountTransaction {
            $account = $this->repository->lockAccount($request->tenantId, $request->account->id);
            if (! $account || ($account->is_deleted && $request->transactionType !== FinancialAccountTransactionType::Reversal)) {
                throw new InvalidTenantRequest('The financial account is not available for this tenant.');
            }

            $newBalance = round((float) $account->balance + ($request->direction === 'debit' ? $request->amount : -$request->amount), 4);
            if ($newBalance < 0 && ! $account->allow_negative_balance) {
                throw new InvalidTenantRequest('The financial account does not have enough balance for this operation.');
            }

            $transaction = $this->repository->create([
                'tenant_id' => $request->tenantId,
                'financial_account_id' => $account->id,
                'transaction_type' => $request->transactionType->value,
                'amount' => $request->amount,
                'direction' => $request->direction,
                'reference_number' => $request->referenceNumber,
                'reference_type' => $request->referenceType,
                'note' => $request->note,
                'created_by' => $request->createdBy,
                'related_transaction_id' => $request->relatedTransactionId,
                'reversed_transaction_id' => $request->reversedTransactionId,
            ]);
            $this->repository->updateBalance($account, $newBalance);

            return $transaction;
        });
    }

    public function listForAccount(int $tenantId, FinancialAccount $account, FinancialAccountTransactionFilter $filter): FinancialAccountTransactionListPage
    {
        if ((int) $account->tenant_id !== $tenantId || $account->is_deleted) {
            throw new InvalidTenantRequest('The financial account is not available for this tenant.');
        }

        return FinancialAccountTransactionListPage::fromPaginator(
            $this->repository->paginateForAccount($tenantId, $account->id, $filter),
        );
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

    public function recordPawnPartialPrincipalCollection(FinancialAccount $account, float $amount, ?string $referenceNumber = null, ?string $referenceType = null, ?string $note = null, ?int $createdBy = null, ?int $relatedTransactionId = null): FinancialAccountTransaction
    {
        return $this->recordType($account, FinancialAccountTransactionType::PawnPartialPrincipalCollection, $amount, 'debit', $referenceNumber, $referenceType, $note, $createdBy, $relatedTransactionId);
    }

    public function recordDebtCreation(FinancialAccount $account, float $amount, ?string $referenceNumber = null, ?string $referenceType = null, ?string $note = null, ?int $createdBy = null, ?int $relatedTransactionId = null): FinancialAccountTransaction
    {
        return $this->recordType($account, FinancialAccountTransactionType::DebtCreation, $amount, 'credit', $referenceNumber, $referenceType, $note, $createdBy, $relatedTransactionId);
    }

    public function recordDebtPayment(FinancialAccount $account, float $amount, ?string $referenceNumber = null, ?string $referenceType = null, ?string $note = null, ?int $createdBy = null, ?int $relatedTransactionId = null): FinancialAccountTransaction
    {
        return $this->recordType($account, FinancialAccountTransactionType::DebtPayment, $amount, 'debit', $referenceNumber, $referenceType, $note, $createdBy, $relatedTransactionId);
    }

    public function recordDebtInterestPayment(FinancialAccount $account, float $amount, ?string $referenceNumber = null, ?string $referenceType = null, ?string $note = null, ?int $createdBy = null, ?int $relatedTransactionId = null): FinancialAccountTransaction
    {
        return $this->recordType($account, FinancialAccountTransactionType::DebtInterestPayment, $amount, 'debit', $referenceNumber, $referenceType, $note, $createdBy, $relatedTransactionId);
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

    public function reverseReference(FinancialAccount $account, string $referenceNumber, string $referenceType, ?int $createdBy = null): void
    {
        DB::transaction(function () use ($account, $referenceNumber, $referenceType, $createdBy): void {
            foreach ($this->repository->unreversedForReference((int) $account->tenant_id, $referenceNumber, $referenceType) as $transaction) {
                $this->record(new FinancialAccountTransactionCreate(
                    tenantId: (int) $account->tenant_id,
                    account: $transaction->financialAccount,
                    transactionType: FinancialAccountTransactionType::Reversal,
                    amount: (float) $transaction->amount,
                    direction: $transaction->direction === 'debit' ? 'credit' : 'debit',
                    referenceNumber: $referenceNumber,
                    referenceType: $referenceType,
                    note: 'Reversal of financial account transaction '.$transaction->id,
                    createdBy: $createdBy,
                    reversedTransactionId: $transaction->id,
                ));
            }
        });
    }

    public function reconcile(?int $tenantId = null, bool $dryRun = false): array
    {
        $summary = ['checked' => 0, 'changed' => 0, 'accounts' => []];
        foreach ($this->repository->accounts($tenantId) as $account) {
            $expected = $this->repository->ledgerBalance((int) $account->tenant_id, $account->id);
            $current = (float) $account->balance;
            $summary['checked']++;
            if (abs($expected - $current) < 0.00005) {
                continue;
            }
            $summary['changed']++;
            $summary['accounts'][] = ['id' => $account->id, 'tenant_id' => $account->tenant_id, 'before' => $current, 'after' => $expected];
            if (! $dryRun) {
                DB::transaction(function () use ($account, $expected): void {
                    $locked = $this->repository->lockAccount((int) $account->tenant_id, $account->id);
                    if ($locked) {
                        $this->repository->updateBalance($locked, $expected);
                    }
                });
            }
        }

        return $summary;
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
