<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\DefaultDataCreate;
use App\Exceptions\InvalidTenantRequest;
use App\Models\CoreModule\ExpenseType;
use App\Models\CoreModule\InterestType;
use App\Models\CoreModule\MaterialType;
use App\Repository\DefaultDataRepository;
use App\Services\BaseTenantService;
use App\Support\TenantScopedCacheKeys;
use Illuminate\Support\Str;

class DefaultDataService extends BaseTenantService
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        private TenantScopedCacheKeys $tenantScopedCacheKeys,
        private DefaultDataRepository $defaultDataRepository,
    )
    {
        //
    }

    public function getMaterialTypes(): array
    {
        $tenantId = $this->resolveCurrentTenantId();

        $version = $this->tenantScopedCacheKeys->currentVersion(
            'material_types',
            1,
            $tenantId
        );

        return cache()->remember(
            $this->tenantScopedCacheKeys->listKey('material_types', $version, $tenantId),
            now()->addDay(),
            fn () => $this->defaultDataRepository->findAllMaterialTypes()
        );
    }

    public function getInterestTypes(): array
    {
        $tenantId = $this->resolveCurrentTenantId();

        $version = $this->tenantScopedCacheKeys->currentVersion(
            'interest_types',
            1,
            $tenantId
        );

        return cache()->remember(
            $this->tenantScopedCacheKeys->listKey('interest_types', $version, $tenantId),
            now()->addDay(),
            fn () => $this->defaultDataRepository->findAllInterestType()
        );
    }

    public function getExpenseTypes(): array
    {
        $tenantId = $this->resolveCurrentTenantId();

        $version = $this->tenantScopedCacheKeys->currentVersion(
            'expense_types',
            1,
            $tenantId
        );

        return cache()->remember(
            $this->tenantScopedCacheKeys->listKey('expense_types', $version, $tenantId),
            now()->addDay(),
            fn () => $this->defaultDataRepository->findAllExpenseTypes()
        );
    }

    public function getExpenseTypeByCode(string $code): ExpenseType
    {
        $tenantId = $this->resolveCurrentTenantId();

        $version = $this->tenantScopedCacheKeys->currentVersion(
            'expense_types',
            1,
            $tenantId
        );

        return cache()->remember(
            $this->tenantScopedCacheKeys->itemKey('expense_types', $code, $version, $tenantId),
            now()->addDay(),
            fn () => $this->defaultDataRepository->findExpenseByCode($code)
        );
    }

    public function getMaterialTypeByCode(string $code): MaterialType
    {
        $tenantId = $this->resolveCurrentTenantId();

        $version = $this->tenantScopedCacheKeys->currentVersion(
            'material_types',
            1,
            $tenantId
        );

        return cache()->remember(
            $this->tenantScopedCacheKeys->itemKey('material_types', $code, $version, $tenantId),
            now()->addDay(),
            fn () => $this->defaultDataRepository->findMaterialByCode($code)
        );
    }

    public function getInterestTypeByCode(string $code): InterestType
    {
        $tenantId = $this->resolveCurrentTenantId();

        $version = $this->tenantScopedCacheKeys->currentVersion(
            'interest_types',
            1,
            $tenantId
        );

        return cache()->remember(
            $this->tenantScopedCacheKeys->itemKey('interest_types', $code, $version, $tenantId),
            now()->addDay(),
            fn () => $this->defaultDataRepository->findInterestByCode($code)
        );
    }

    public function createDefaultMaterialType(string $name,string $code):void
    {
        $this->defaultDataRepository->createMaterialType([
            'tenant_id' => null,
            'name' => $name,
            'code' => $code,
            'is_default' => true,
        ]);

        //clear all tenant cache of material types
        $this->tenantScopedCacheKeys->bumpGlobalVersion('material_types');
    }

    public function createDefaultInterestType(string $name,string $code,int $durationInDays):void
    {
        $this->defaultDataRepository->createInterestType([
            'tenant_id' => null,
            'name' => $name,
            'code' => $code,
            'duration_in_days' => $durationInDays,
            'is_default' => true,
        ]);

        //clear all tenant cache of interest types
        $this->tenantScopedCacheKeys->bumpGlobalVersion('interest_types');
    }

    public function createDefaultExpenseType(string $name,string $code):void
    {
        $this->defaultDataRepository->createExpenseType([
            'tenant_id' => null,
            'name' => $name,
            'code' => $code,
            'is_default' => true,
        ]);

        //clear all tenant cache of expense types
        $this->tenantScopedCacheKeys->bumpGlobalVersion('expense_types');
    }

    public function createCurrentTenantMaterialType(DefaultDataCreate $request): array
    {
        $item = $this->defaultDataRepository->createMaterialType([
            'tenant_id' => $this->resolveCurrentTenantId(),
            'name' => $request->name,
            'code' => $this->resolveCode(MaterialType::class, $request->code ?? $request->name),
            'is_default' => false,
        ]);

        $this->tenantScopedCacheKeys->bumpTenantVersion('material_types');

        return $item->toArray();
    }

    public function createCurrentTenantInterestType(DefaultDataCreate $request): array
    {
        $item = $this->defaultDataRepository->createInterestType([
            'tenant_id' => $this->resolveCurrentTenantId(),
            'name' => $request->name,
            'code' => $this->resolveCode(InterestType::class, $request->code ?? $request->name),
            'duration_in_days' => (int) ($request->durationInDays ?? 30),
            'is_default' => false,
        ]);

        $this->tenantScopedCacheKeys->bumpTenantVersion('interest_types');

        return $item->toArray();
    }

    public function createCurrentTenantExpenseType(DefaultDataCreate $request): array
    {
        $item = $this->defaultDataRepository->createExpenseType([
            'tenant_id' => $this->resolveCurrentTenantId(),
            'name' => $request->name,
            'code' => $this->resolveCode(ExpenseType::class, $request->code ?? $request->name),
            'is_default' => false,
        ]);

        $this->tenantScopedCacheKeys->bumpTenantVersion('expense_types');

        return $item->toArray();
    }

    public function deleteCurrentTenantExpenseType(string $code)
    {
        $tenantId = $this->resolveCurrentTenantId();

        $deleted = ExpenseType::query()
            ->where('code', $code)
            ->where('tenant_id', $tenantId)
            ->delete();

        if ($deleted > 0) {
            $this->tenantScopedCacheKeys->bumpTenantVersion('expense_types', $tenantId);
            return;
        }

        throw new InvalidTenantRequest(
            'Expense type not found or does not belong to the current tenant.'
        );
    }

    public function deleteCurrentTenantMaterialType(string $code)
    {
        $tenantId = $this->resolveCurrentTenantId();

        $deleted = MaterialType::query()
            ->where('code', $code)
            ->where('tenant_id', $tenantId)
            ->delete();

        if ($deleted > 0) {
            $this->tenantScopedCacheKeys->bumpTenantVersion('material_types', $tenantId);
            return;
        }

        throw new InvalidTenantRequest(
            'Material type not found or does not belong to the current tenant.'
        );
    }

    public function deleteCurrentTenantInterestType(string $code): void
    {
        $tenantId = $this->resolveCurrentTenantId();

        $deleted = InterestType::query()
            ->where('code', $code)
            ->where('tenant_id', $tenantId)
            ->delete();

        if ($deleted > 0) {
            $this->tenantScopedCacheKeys->bumpTenantVersion('interest_types', $tenantId);
            return;
        }

        throw new InvalidTenantRequest(
            'Interest type not found or does not belong to the current tenant.'
        );
    }

    protected function resolveCode(string $modelClass, string $value): string
    {
        $base = Str::slug($value, '_') ?: 'type';
        $code = Str::upper($base);
        $suffix = 1;
        $tenantId = $this->resolveCurrentTenantId();

        while ($this->defaultDataRepository->codeExists($modelClass, $code)) {
            $code = Str::upper($base).'_'.$tenantId.'_'.$suffix;
            $suffix++;
        }

        return $code;
    }
}
