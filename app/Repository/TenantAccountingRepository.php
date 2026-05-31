<?php

namespace App\Repository;

use App\Models\CoreModule\TenantAccounting;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use App\DataObjects\ResponseObjects\LedgerEntry;
use App\Support\AccountingReferenceMapper;

class TenantAccountingRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return TenantAccounting::query()
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function listIncomingTransactions(int $perPage = 15): LengthAwarePaginator
    {
        return TenantAccounting::query()
            ->where('transaction_type', 'incoming')
            ->whereDate('created_at', Carbon::today())
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function listOutgoingTransactions(int $perPage = 15): LengthAwarePaginator
    {
        return TenantAccounting::query()
            ->where('transaction_type', 'outgoing')
            ->whereDate('created_at', Carbon::today())
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function create(array $data): TenantAccounting
    {
        return TenantAccounting::query()->create($data);
    }

    public function update(TenantAccounting $accounting, array $data): TenantAccounting
    {
        $accounting->update($data);

        return $accounting->refresh();
    }

    public function updateWithLock(TenantAccounting $accounting, array $data): TenantAccounting
    {
        $lockedAccounting = TenantAccounting::query()
            ->whereKey($accounting->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        return $this->update($lockedAccounting, $data);
    }

    public function delete(TenantAccounting $accounting): void
    {
        $accounting->delete();
    }

    public function findById(int $accountingId): ?TenantAccounting
    {
        return TenantAccounting::query()->find($accountingId);
    }

    public function findByIdWithLock(int $accountingId): ?TenantAccounting
    {
        return TenantAccounting::query()
            ->whereKey($accountingId)
            ->lockForUpdate()
            ->first();
    }

    public function findByReference(Model $reference): ?TenantAccounting
    {
        return TenantAccounting::query()
            ->where('reference_type', $reference::class)
            ->where('reference_id', $reference->getKey())
            ->first();
    }

    public function findByReferenceWithLock(Model $reference): ?TenantAccounting
    {
        return TenantAccounting::query()
            ->where('reference_type', $reference::class)
            ->where('reference_id', $reference->getKey())
            ->lockForUpdate()
            ->first();
    }

    public function paginateAccountingLedger(Carbon $startDate, Carbon $endDate, int $perPage = 15): LengthAwarePaginator
    {
        return TenantAccounting::query()
            ->whereBetween('created_at', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()])
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function getAccountingLedger(Carbon $startDate, Carbon $endDate): array
    {
        return TenantAccounting::query()
            ->whereBetween('created_at', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function balanceBefore(Carbon $startDate): float
    {
        return (float) TenantAccounting::query()
            ->where('created_at', '<', $startDate->copy()->startOfDay())
            ->selectRaw("
                COALESCE(SUM(CASE
                    WHEN transaction_type = 'incoming' THEN amount
                    WHEN transaction_type = 'outgoing' THEN -amount
                    ELSE 0
                END), 0) as balance
            ")
            ->value('balance');
    }

    public function balanceBeforeLedgerRow(Carbon $startDate, int $offset): float
    {
        $balance = $this->balanceBefore($startDate);

        if ($offset <= 0) {
            return $balance;
        }

        $previousRows = TenantAccounting::query()
            ->where('created_at', '>=', $startDate->copy()->startOfDay())
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($offset)
            ->get();

        foreach ($previousRows as $accounting) {
            $balance += $accounting->transaction_type === 'incoming'
                ? (float) $accounting->amount
                : -((float) $accounting->amount);
        }

        return $balance;
    }

    public function mapLedgerEntries(iterable $accountings, float $openingBalance): array
    {
        $balance = $openingBalance;

        return collect($accountings)
            ->map(function (TenantAccounting $accounting) use (&$balance) {
                $debit = $accounting->transaction_type === 'incoming' ? (float) $accounting->amount : 0.0;
                $credit = $accounting->transaction_type === 'outgoing' ? (float) $accounting->amount : 0.0;
                $balance += $debit - $credit;

                return new LedgerEntry(
                    id: $accounting->id,
                    createdAt: $accounting->created_at,
                    description: $accounting->description,
                    debit: $debit,
                    credit: $credit,
                    balance: $balance,
                    referenceType: $accounting->reference_type,
                    referenceId: $accounting->reference_id,
                    referenceLabel: AccountingReferenceMapper::label($accounting->reference_type),
                );
            })
            ->toArray();
    }
}
