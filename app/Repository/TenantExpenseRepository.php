<?php

namespace App\Repository;


use App\Models\CoreModule\TenantExpense;
use App\Exceptions\RequiredValueMissing;
use App\Support\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TenantExpenseRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return TenantExpense::query()
            ->with(['expenseType', 'creator'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function create(array $data): TenantExpense
    {
        $this->requireValue($data, 'code');

        return TenantExpense::query()
            ->create($data)
            ->load(['expenseType', 'creator']);
    }

    protected function requireValue(array $data, string $key): void
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            throw new RequiredValueMissing("Tenant expense {$key} is required.");
        }
    }

    public function update(TenantExpense $expense, array $data): TenantExpense
    {
        $expense->update($data);

        return $expense->refresh()->load(['expenseType', 'creator']);
    }

    public function updateWithLock(TenantExpense $expense, array $data): TenantExpense
    {
        $lockedExpense = TenantExpense::query()
            ->whereKey($expense->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        return $this->update($lockedExpense, $data);
    }

    public function delete(TenantExpense $expense): void
    {
        $expense->delete();
    }

    public function findById(int $expenseId): ?TenantExpense
    {
        return TenantExpense::query()
            ->with(['expenseType', 'creator'])
            ->find($expenseId);
    }

    public function findByCode(string $code): ?TenantExpense
    {
        return TenantExpense::query()
            ->with(['expenseType', 'creator'])
            ->where('code', $code)
            ->first();
    }

    public function findByIdWithLock(int $expenseId): ?TenantExpense
    {
        return TenantExpense::query()
            ->with(['expenseType', 'creator'])
            ->whereKey($expenseId)
            ->lockForUpdate()
            ->first();
    }

    public function findByCodeWithLock(string $code): ?TenantExpense
    {
        return TenantExpense::query()
            ->with(['expenseType', 'creator'])
            ->where('code', $code)
            ->lockForUpdate()
            ->first();
    }


}
