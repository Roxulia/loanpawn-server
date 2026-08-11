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

        return $this->record(new FinancialAccountTransactionCreate(
            tenantId: (int) $account->tenant_id,
            account: $account,
            transactionType: FinancialAccountTransactionType::OpeningBalance,
            amount: $amount,
            direction: 'debit',
            note: 'Opening balance',
            createdBy: $createdBy,
        ));
    }
}
