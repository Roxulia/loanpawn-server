<?php

namespace App\Repository;


use App\Support\TenantContext;
use App\Models\CoreModule\ItemCategoryType;
use App\Models\CoreModule\MaterialType;
use App\Models\CoreModule\InterestType;
use App\Models\CoreModule\ExpenseType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class DefaultDataRepository
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function findAllMaterialTypes(): array
    {
        return MaterialType::query()
            ->where(function ($query) {
                $query->whereNull('tenant_id')
                    ->orWhere('tenant_id', app(TenantContext::class)->id());
            })
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    public function paginateMaterialTypes(int $perPage = 15): LengthAwarePaginator
    {
        return MaterialType::query()
            ->where(function ($query) {
                $query->whereNull('tenant_id')
                    ->orWhere('tenant_id', app(TenantContext::class)->id());
            })
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function findAllItemCategoryTypes(): array
    {
        return ItemCategoryType::query()
            ->where(function ($query) {
                $query->whereNull('tenant_id')
                    ->orWhere('tenant_id', app(TenantContext::class)->id());
            })
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    public function paginateItemCategoryTypes(int $perPage = 15): LengthAwarePaginator
    {
        return ItemCategoryType::query()
            ->where(function ($query) {
                $query->whereNull('tenant_id')
                    ->orWhere('tenant_id', app(TenantContext::class)->id());
            })
            ->orderBy('name')
            ->paginate($perPage);
    }


    public function findAllInterestType():array{
        return InterestType::query()
            ->where(function ($query) {
                $query->whereNull('tenant_id')
                    ->orWhere('tenant_id', app(TenantContext::class)->id());
            })
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    public function paginateInterestTypes(int $perPage = 15): LengthAwarePaginator
    {
        return InterestType::query()
            ->where(function ($query) {
                $query->whereNull('tenant_id')
                    ->orWhere('tenant_id', app(TenantContext::class)->id());
            })
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function findAllExpenseTypes(): array
    {
        return ExpenseType::query()
             ->where(function ($query) {
                 $query->whereNull('tenant_id')
                       ->orWhere('tenant_id', app(TenantContext::class)->id());
             })
             ->orderBy('name')
             ->get()
             ->toArray();
    }

    public function paginateExpenseTypes(int $perPage = 15): LengthAwarePaginator
    {
        return ExpenseType::query()
            ->where(function ($query) {
                $query->whereNull('tenant_id')
                    ->orWhere('tenant_id', app(TenantContext::class)->id());
            })
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function createMaterialType(array $data): MaterialType
    {
        return MaterialType::query()->create($data);
    }

    public function createItemCategoryType(array $data): ItemCategoryType
    {
        return ItemCategoryType::query()->create($data);
    }

    public function createInterestType(array $data): InterestType
    {
        return InterestType::query()->create($data);
    }

    public function createExpenseType(array $data): ExpenseType
    {
        return ExpenseType::query()->create($data);
    }

    public function findExpenseByCode(string $code) : ?ExpenseType
    {
        return ExpenseType::query()->where('code',$code)->first();
    }

    public function findMaterialByCode(string $code) : ?MaterialType
    {
        return MaterialType::query()->where('code',$code)->first();
    }

    public function findItemCategoryByCode(string $code) : ?ItemCategoryType
    {
        return ItemCategoryType::query()->where('code', $code)->first();
    }

    public function findInterestByCode(string $code) : ?InterestType
    {
        return InterestType::query()->where('code',$code)->first();
    }

    public function codeExists(string $modelClass, string $code): bool
    {
        return $modelClass::query()->where('code', $code)->exists();
    }
}
