<?php

namespace App\Services\TenantModule;

use App\Models\CatalogModule\CatalogCategory;
use App\Models\CatalogModule\CatalogUnit;
use App\Repository\CatalogTaxonomyRepository;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogTaxonomyService
{
    public function __construct(
        private CatalogTaxonomyRepository $repository,
        private TenantContext $tenantContext,
    ) {
    }

    public function categories(): array
    {
        return $this->repository
            ->categories($this->tenantId())
            ->map(function (CatalogCategory $category): array {
                return $this->categoryResource($category);
            })
            ->all();
    }

    public function units(): array
    {
        return $this->repository
            ->units($this->tenantId())
            ->map(function (CatalogUnit $unit): array {
                return $this->unitResource($unit);
            })
            ->all();
    }

    public function unitsForManagement(): array
    {
        return $this->repository
            ->unitsForManagement($this->tenantId())
            ->map(function (CatalogUnit $unit): array {
                return $this->unitResource($unit);
            })
            ->all();
    }

    public function defaultUnitId(): ?int
    {
        return $this->repository->defaultUnit($this->tenantId())?->id;
    }

    public function categoryIsAvailable(int $categoryId): bool
    {
        return $this->repository->findCategory($this->tenantId(), $categoryId) !== null;
    }

    public function unitIsAvailable(int $unitId): bool
    {
        return $this->repository->findUnit($this->tenantId(), $unitId) !== null;
    }

    public function unitIdForCode(string $unitCode): ?int
    {
        return $this->repository->findUnitByCode($this->tenantId(), $unitCode)?->id;
    }

    public function unitIdForCodeInTenant(int $tenantId, string $unitCode): ?int
    {
        // Resolve catalog units for background work that carries an explicit tenant ID.
        return $this->repository->findUnitByCode($tenantId, $unitCode)?->id;
    }

    public function createCategory(string $name): array
    {
        $tenantId = $this->tenantId();
        $name = trim($name);

        if ($this->repository->findCategoryByName($tenantId, $name)) {
            throw ValidationException::withMessages([
                'name' => ['This category name is already in use.'],
            ]);
        }

        return DB::transaction(function () use ($tenantId, $name): array {
            $category = $this->repository->createCategory($tenantId, ['name' => $name]);

            return $this->categoryResource($category);
        });
    }

    public function updateCategory(int $id, array $data): array
    {
        $tenantId = $this->tenantId();
        $category = $this->repository->findCategory($tenantId, $id);
        abort_if($category === null, 404);

        if ((int) $data['update_key'] !== $category->update_key) {
            throw ValidationException::withMessages([
                'update_key' => ['This category has changed. Reload it and try again.'],
            ]);
        }

        if (isset($data['name'])) {
            $name = trim($data['name']);
            $existing = $this->repository->findCategoryByName($tenantId, $name);

            if ($existing !== null && $existing->id !== $category->id) {
                throw ValidationException::withMessages([
                    'name' => ['This category name is already in use.'],
                ]);
            }

            $data['name'] = $name;
        }

        $data['update_key'] = $category->update_key + 1;

        return DB::transaction(function () use ($category, $data): array {
            $updated = $this->repository->updateCategory($category, $data);

            return $this->categoryResource($updated);
        });
    }

    public function createUnit(array $data): array
    {
        $tenantId = $this->tenantId();
        $code = strtolower(trim($data['code']));

        if ($code === 'unit') {
            throw ValidationException::withMessages([
                'code' => ['The default Unit code is reserved.'],
            ]);
        }

        if ($this->repository->findTenantUnitByCode($tenantId, $code)) {
            throw ValidationException::withMessages([
                'code' => ['This unit code is already in use.'],
            ]);
        }

        $unit = [
            'code' => $code,
            'name' => trim($data['name']),
            'symbol' => $data['symbol'] ?? null,
        ];

        return DB::transaction(function () use ($tenantId, $unit): array {
            $created = $this->repository->createUnit($tenantId, $unit);

            return $this->unitResource($created);
        });
    }

    public function updateUnit(int $id, array $data): array
    {
        $unit = $this->repository->findTenantUnit($this->tenantId(), $id);
        abort_if($unit === null, 404);

        $isActive = (bool) $data['is_active'];
        if ($unit->code === 'unit' && !$isActive) {
            throw ValidationException::withMessages([
                'is_active' => ['The default Unit cannot be deactivated.'],
            ]);
        }

        return DB::transaction(function () use ($unit, $isActive): array {
            $updated = $this->repository->updateUnit($unit, ['is_active' => $isActive]);

            return $this->unitResource($updated);
        });
    }

    private function categoryResource(CatalogCategory $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'is_active' => $category->is_active,
            'update_key' => $category->update_key,
        ];
    }

    private function unitResource(CatalogUnit $unit): array
    {
        return [
            'id' => $unit->id,
            'tenant_id' => $unit->tenant_id,
            'code' => $unit->code,
            'name' => $unit->name,
            'symbol' => $unit->symbol,
            'is_active' => $unit->is_active,
            'is_system' => $unit->is_system,
        ];
    }

    private function tenantId(): int
    {
        return $this->tenantContext->id() ?? abort(403);
    }
}
