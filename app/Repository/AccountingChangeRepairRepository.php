<?php

namespace App\Repository;

use App\Models\CoreModule\TenantAccounting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AccountingChangeRepairRepository
{
    public function chunkInterestPayments(int $chunkSize, callable $callback): void
    {
        DB::table('pawn_interest_payments')
            ->where('is_deleted', false)
            ->where('is_paid', true)
            ->where('change_amount', '>', 0)
            ->select(['id', 'tenant_id', 'payment_amount', 'change_amount'])
            ->orderBy('id')
            ->chunkById($chunkSize, $callback, 'id');
    }

    public function chunkPaidDebts(int $chunkSize, callable $callback): void
    {
        DB::table('tenant_debts')
            ->where('is_deleted', false)
            ->where('is_paid', true)
            ->select(['id', 'tenant_id', 'amount'])
            ->orderBy('id')
            ->chunkById($chunkSize, $callback, 'id');
    }

    public function chunkRedemptions(int $chunkSize, callable $callback): void
    {
        DB::table('pawn_redemptions')
            ->where('is_deleted', false)
            ->where('change_amount', '>', 0)
            ->select(['id', 'tenant_id', 'received_amount', 'change_amount'])
            ->orderBy('id')
            ->chunkById($chunkSize, $callback, 'id');
    }

    /** @param string[] $referenceTypes */
    public function activeAccountingRows(int $tenantId, array $referenceTypes, int $referenceId): Collection
    {
        return TenantAccounting::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('reference_type', $referenceTypes)
            ->where('reference_id', $referenceId)
            ->where('is_deleted', false)
            ->orderBy('id')
            ->get();
    }

    public function softDeleteOutgoing(int $tenantId, int $accountingId): void
    {
        $accounting = TenantAccounting::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereKey($accountingId)
            ->where('transaction_type', 'outgoing')
            ->where('is_deleted', false)
            ->lockForUpdate()
            ->firstOrFail();

        $accounting->forceFill([
            'is_deleted' => true,
            'update_key' => (int) $accounting->update_key + 1,
        ])->save();
    }

    public function createOutgoingChange(
        int $tenantId,
        string $referenceType,
        int $referenceId,
        string $amount,
        string $description,
    ): void {
        TenantAccounting::query()->withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenantId,
            'description' => $description,
            'transaction_type' => 'outgoing',
            'amount' => $amount,
            'created_by' => null,
            'reference_id' => $referenceId,
            'reference_type' => $referenceType,
        ]);
    }
}
