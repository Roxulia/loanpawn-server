<?php

namespace App\Repository\Accounting;

use App\Models\FinancialAccount;
use App\Models\PlatformModule\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LegacyOperationAccountBackfillRepository
{
    public function tenantIds(): Collection
    {
        return Tenant::query()->orderBy('id')->pluck('id');
    }

    public function activeDefaultAccount(int $tenantId): ?FinancialAccount
    {
        return FinancialAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->where('is_active', true)
            ->where('is_deleted', false)
            ->first();
    }

    public function hasFinancialAccount(int $tenantId): bool
    {
        return FinancialAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('is_deleted', false)
            ->exists();
    }

    /** @return array<string, int> */
    public function missingCounts(int $tenantId): array
    {
        return [
            'loan_contract_account' => DB::table('pawn_loan_contract_slips')->where('tenant_id', $tenantId)->whereNull('account_id')->count(),
            'interest_created_account' => DB::table('pawn_interest_payments')->where('tenant_id', $tenantId)->whereNull('created_account_id')->count(),
            'interest_accept_account' => DB::table('pawn_interest_payments')->where('tenant_id', $tenantId)->where('is_paid', true)->whereNull('accept_account_id')->count(),
            'redemption_account' => DB::table('pawn_redemptions')->where('tenant_id', $tenantId)->whereNull('account_id')->count(),
            'debt_created_account' => DB::table('tenant_debts')->where('tenant_id', $tenantId)->whereNull('created_account_id')->count(),
            'debt_accept_account' => DB::table('tenant_debts')->where('tenant_id', $tenantId)->where('is_paid', true)->whereNull('accept_account_id')->count(),
        ];
    }

    /** @return array<string, int> */
    public function backfill(int $tenantId, int $accountId): array
    {
        return [
            'loan_contract_account' => DB::table('pawn_loan_contract_slips')->where('tenant_id', $tenantId)->whereNull('account_id')->update(['account_id' => $accountId]),
            'interest_created_account' => DB::table('pawn_interest_payments')->where('tenant_id', $tenantId)->whereNull('created_account_id')->update(['created_account_id' => $accountId]),
            'interest_accept_account' => DB::table('pawn_interest_payments')->where('tenant_id', $tenantId)->where('is_paid', true)->whereNull('accept_account_id')->update(['accept_account_id' => $accountId]),
            'redemption_account' => DB::table('pawn_redemptions')->where('tenant_id', $tenantId)->whereNull('account_id')->update(['account_id' => $accountId]),
            'debt_created_account' => DB::table('tenant_debts')->where('tenant_id', $tenantId)->whereNull('created_account_id')->update(['created_account_id' => $accountId]),
            'debt_accept_account' => DB::table('tenant_debts')->where('tenant_id', $tenantId)->where('is_paid', true)->whereNull('accept_account_id')->update(['accept_account_id' => $accountId]),
        ];
    }
}
