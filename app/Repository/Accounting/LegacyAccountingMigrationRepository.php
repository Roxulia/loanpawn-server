<?php

namespace App\Repository\Accounting;

use App\Models\CoreModule\TenantAccounting;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountTransaction;
use App\Models\TenantAccountingTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LegacyAccountingMigrationRepository
{
    public function tenantIds(): Collection
    {
        return TenantAccounting::query()->withoutGlobalScopes()->distinct()->orderBy('tenant_id')->pluck('tenant_id');
    }

    public function legacyRows(int $tenantId): Collection
    {
        return TenantAccounting::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('id')->get();
    }

    public function migratedAccounting(int $tenantId, int $legacyId): ?TenantAccountingTransactions
    {
        return TenantAccountingTransactions::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('legacy_accounting_id', $legacyId)->first();
    }

    public function createAccounting(array $data): TenantAccountingTransactions
    {
        $accounting = new TenantAccountingTransactions;
        $accounting->forceFill($data)->save();

        return $accounting;
    }

    public function defaultAccount(int $tenantId): ?FinancialAccount
    {
        return FinancialAccount::query()->where('tenant_id', $tenantId)->where('is_default', true)
            ->where('is_active', true)->where('is_deleted', false)->with('currency')->first();
    }

    public function financialPostingExists(int $tenantId, int $accountingId): bool
    {
        return FinancialAccountTransaction::query()->where('tenant_id', $tenantId)
            ->where('related_transaction_id', $accountingId)->exists();
    }

    public function createFinancialPosting(array $data): FinancialAccountTransaction
    {
        $transaction = new FinancialAccountTransaction;
        $transaction->forceFill($data)->save();

        return $transaction;
    }

    public function updateAccountBalanceFromLedger(FinancialAccount $account): void
    {
        $balance = FinancialAccountTransaction::query()->where('tenant_id', $account->tenant_id)
            ->where('financial_account_id', $account->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount ELSE -amount END), 0) AS balance")
            ->value('balance');

        $account->update(['balance' => $balance, 'update_key' => $account->update_key + 1]);
    }

    public function oldMovement(int $tenantId): array
    {
        return $this->movement(DB::table('tenant_accountings')->where('tenant_id', $tenantId)->where('is_deleted', false), 'transaction_type');
    }

    public function migratedMovement(int $tenantId, int $accountId): array
    {
        $query = DB::table('financial_account_transactions as fat')
            ->join('tenant_accounting_transactions as tat', 'tat.id', '=', 'fat.related_transaction_id')
            ->where('fat.tenant_id', $tenantId)->where('fat.financial_account_id', $accountId)
            ->whereNotNull('tat.legacy_accounting_id')->where('tat.is_deleted', false);

        return $this->financialMovement($query);
    }

    public function fullMovement(int $tenantId, int $accountId): array
    {
        return $this->financialMovement(
            DB::table('financial_account_transactions as fat')
                ->where('fat.tenant_id', $tenantId)
                ->where('fat.financial_account_id', $accountId)
        );
    }

    private function movement($query, string $directionColumn): array
    {
        $row = $query->selectRaw("COALESCE(SUM(CASE WHEN {$directionColumn} = 'incoming' THEN amount ELSE 0 END), 0) incoming")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$directionColumn} = 'outgoing' THEN amount ELSE 0 END), 0) outgoing")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$directionColumn} = 'internal' THEN amount ELSE 0 END), 0) internal")->first();

        return $this->totals((float) $row->incoming, (float) $row->outgoing, (float) $row->internal);
    }

    private function financialMovement($query): array
    {
        $row = $query->selectRaw("COALESCE(SUM(CASE WHEN fat.direction = 'debit' THEN fat.amount ELSE 0 END), 0) incoming")
            ->selectRaw("COALESCE(SUM(CASE WHEN fat.direction = 'credit' THEN fat.amount ELSE 0 END), 0) outgoing")->first();

        return $this->totals((float) $row->incoming, (float) $row->outgoing, 0.0);
    }

    private function totals(float $incoming, float $outgoing, float $internal): array
    {
        return ['incoming' => $incoming, 'outgoing' => $outgoing, 'internal' => $internal, 'balance' => $incoming - $outgoing];
    }
}
