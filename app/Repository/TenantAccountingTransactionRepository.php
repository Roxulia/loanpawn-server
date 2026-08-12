<?php

namespace App\Repository;

use App\DataObjects\ResponseObjects\LedgerEntry;
use App\Models\TenantAccountingTransactions;
use App\Support\AccountingReferenceMapper;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

class TenantAccountingTransactionRepository
{
    public function paginate(int $perPage = 15, ?string $search = null): LengthAwarePaginator
    {
        $query = TenantAccountingTransactions::query()
            ->where('is_deleted', false)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if ($search !== null) {
            $query->where(function ($query) use ($search): void {
                $query->where('description', 'like', "%{$search}%")
                    ->orWhere('transaction_direction', 'like', "%{$search}%")
                    ->orWhere('accounting_category', 'like', "%{$search}%")
                    ->orWhere('reference_type', 'like', "%{$search}%");

                if (is_numeric($search)) {
                    $query->orWhere('amount', (float) $search)
                        ->orWhere('reporting_amount', (float) $search);
                }
            });
        }

        return $query->paginate($perPage);
    }

    public function listIncomingTransactions(int $perPage = 15): LengthAwarePaginator
    {
        return $this->listByDirection('incoming', $perPage);
    }

    public function listOutgoingTransactions(int $perPage = 15): LengthAwarePaginator
    {
        return $this->listByDirection('outgoing', $perPage);
    }

    public function create(array $data): TenantAccountingTransactions
    {
        return TenantAccountingTransactions::query()->create($data);
    }

    public function update(TenantAccountingTransactions $accounting, array $data): TenantAccountingTransactions
    {
        $accounting->update($data);

        return $accounting->refresh();
    }

    public function updateWithLock(TenantAccountingTransactions $accounting, array $data): TenantAccountingTransactions
    {
        $lockedAccounting = TenantAccountingTransactions::query()
            ->whereKey($accounting->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        return $this->update($lockedAccounting, $data);
    }

    public function delete(TenantAccountingTransactions $accounting): void
    {
        $accounting->delete();
    }

    public function findById(int $accountingId): ?TenantAccountingTransactions
    {
        return TenantAccountingTransactions::query()->find($accountingId);
    }

    public function findByIdWithLock(int $accountingId): ?TenantAccountingTransactions
    {
        return TenantAccountingTransactions::query()
            ->whereKey($accountingId)
            ->lockForUpdate()
            ->first();
    }

    public function findByReference(Model $reference): ?TenantAccountingTransactions
    {
        return $this->referenceQuery($reference)->first();
    }

    public function findByReferenceWithLock(Model $reference): ?TenantAccountingTransactions
    {
        return $this->referenceQuery($reference)->lockForUpdate()->first();
    }

    public function paginateAccountingLedger(Carbon $startDate, Carbon $endDate, int $perPage = 15): LengthAwarePaginator
    {
        return $this->ledgerQuery($startDate, $endDate)->paginate($perPage);
    }

    public function getAccountingLedger(Carbon $startDate, Carbon $endDate): array
    {
        return $this->ledgerQuery($startDate, $endDate)->get()->all();
    }

    public function balanceBefore(Carbon $startDate): float
    {
        return (float) TenantAccountingTransactions::query()
            ->where('is_deleted', false)
            ->where('occurred_at', '<', $startDate->copy()->startOfDay())
            ->selectRaw("COALESCE(SUM(CASE
                WHEN transaction_direction = 'incoming' THEN COALESCE(reporting_amount, amount)
                WHEN transaction_direction = 'outgoing' THEN -COALESCE(reporting_amount, amount)
                ELSE 0
            END), 0) as balance")
            ->value('balance');
    }

    public function balanceBeforeLedgerRow(Carbon $startDate, int $offset): float
    {
        $balance = $this->balanceBefore($startDate);

        if ($offset <= 0) {
            return $balance;
        }

        $previousRows = TenantAccountingTransactions::query()
            ->where('is_deleted', false)
            ->where('occurred_at', '>=', $startDate->copy()->startOfDay())
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit($offset)
            ->get();

        foreach ($previousRows as $accounting) {
            $amount = (float) ($accounting->reporting_amount ?? $accounting->amount);
            $balance += match ($accounting->transaction_direction) {
                'incoming' => $amount,
                'outgoing' => -$amount,
                default => 0.0,
            };
        }

        return $balance;
    }

    public function mapLedgerEntries(iterable $accountings, float $openingBalance): array
    {
        $balance = $openingBalance;

        return collect($accountings)
            ->map(function (TenantAccountingTransactions $accounting) use (&$balance): LedgerEntry {
                $amount = (float) ($accounting->reporting_amount ?? $accounting->amount);
                $debit = $accounting->transaction_direction === 'incoming' ? $amount : 0.0;
                $credit = $accounting->transaction_direction === 'outgoing' ? $amount : 0.0;
                $balance += $debit - $credit;

                return new LedgerEntry(
                    id: $accounting->id,
                    createdAt: $accounting->occurred_at,
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

    public function allTimeNetBalance(): float
    {
        return (float) TenantAccountingTransactions::query()
            ->where('is_deleted', false)
            ->selectRaw("COALESCE(SUM(CASE
                WHEN transaction_direction = 'incoming' THEN COALESCE(reporting_amount, amount)
                WHEN transaction_direction = 'outgoing' THEN -COALESCE(reporting_amount, amount)
                ELSE 0
            END), 0) as balance")
            ->value('balance');
    }

    public function transactionTotalBetween(string $transactionDirection, Carbon $startDate, Carbon $endDate): float
    {
        return (float) TenantAccountingTransactions::query()
            ->where('is_deleted', false)
            ->where('transaction_direction', $transactionDirection)
            ->whereBetween('occurred_at', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()])
            ->selectRaw('COALESCE(SUM(COALESCE(reporting_amount, amount)), 0) as total')
            ->value('total');
    }

    private function listByDirection(string $direction, int $perPage): LengthAwarePaginator
    {
        return TenantAccountingTransactions::query()
            ->where('is_deleted', false)
            ->where('transaction_direction', $direction)
            ->whereDate('occurred_at', Carbon::today())
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    private function referenceQuery(Model $reference)
    {
        return TenantAccountingTransactions::query()
            ->where('reference_type', $reference::class)
            ->where('reference_id', $reference->getKey());
    }

    private function ledgerQuery(Carbon $startDate, Carbon $endDate)
    {
        return TenantAccountingTransactions::query()
            ->where('is_deleted', false)
            ->whereBetween('occurred_at', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()])
            ->orderBy('occurred_at')
            ->orderBy('id');
    }
}
