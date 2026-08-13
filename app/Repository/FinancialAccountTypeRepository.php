<?php

namespace App\Repository;

use App\Models\FinancialAccountTypes;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class FinancialAccountTypeRepository
{
    public function paginateActiveVisibleToTenant(int $tenantId, int $perPage = 15): LengthAwarePaginator
    {
        return FinancialAccountTypes::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function findOwnedByCode(int $tenantId, string $code): ?FinancialAccountTypes
    {
        return FinancialAccountTypes::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->first();
    }

    public function create(array $data): FinancialAccountTypes
    {
        return FinancialAccountTypes::query()->create($data)->refresh();
    }

    public function update(FinancialAccountTypes $type, array $data): FinancialAccountTypes
    {
        $type->update($data);

        return $type->refresh();
    }
}
