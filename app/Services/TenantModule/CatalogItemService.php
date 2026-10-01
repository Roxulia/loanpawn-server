<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\CatalogItemCreate;
use App\Models\CatalogModule\CatalogItem;
use App\Repository\CatalogItemRepository;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogItemService
{
    public function __construct(
        private CatalogItemRepository $repository,
        private CatalogTaxonomyService $taxonomyService,
        private TenantContext $tenantContext,
    ) {
    }

    public function search(?string $search): array
    {
        return $this->repository
            ->search($this->tenantId(), $search)
            ->map(function (CatalogItem $item): array {
                return $this->resource($item);
            })
            ->all();
    }

    public function availableForInventory(int $id): ?array
    {
        $item = $this->repository->findForTenant($this->tenantId(), $id);
        if ($item === null || ! $item->is_active) {
            return null;
        }

        return $this->resource($item);
    }

    public function create(CatalogItemCreate $request): array
    {
        $tenantId = $this->tenantId();
        $data = $request->toArray();
        $data['unit_id'] ??= $this->taxonomyService->defaultUnitId();

        if (!$data['unit_id']) {
            throw ValidationException::withMessages([
                'unit_id' => ['No active default unit is configured.'],
            ]);
        }

        $this->validateReferences($data);

        return DB::transaction(function () use ($tenantId, $data): array {
            $item = $this->repository->create($tenantId, $data);

            return $this->resource($item);
        });
    }

    public function update(int $id, array $data): array
    {
        $tenantId = $this->tenantId();

        return DB::transaction(function () use ($tenantId, $id, $data): array {
            $item = $this->repository->findForTenant($tenantId, $id, true);
            abort_if($item === null, 404);

            if ((int) $data['update_key'] !== $item->update_key) {
                throw ValidationException::withMessages([
                    'update_key' => ['This catalog item has changed. Reload it and try again.'],
                ]);
            }

            $this->validateReferences($data);
            $item = $this->repository->update($item, $data);

            return $this->resource($item->load(['category:id,name', 'unit:id,name,symbol']));
        });
    }

    private function validateReferences(array $data): void
    {
        if (!empty($data['category_id'])
            && !$this->taxonomyService->categoryIsAvailable((int) $data['category_id'])) {
            throw ValidationException::withMessages([
                'category_id' => ['The selected category is unavailable.'],
            ]);
        }

        if (!$this->taxonomyService->unitIsAvailable((int) $data['unit_id'])) {
            throw ValidationException::withMessages([
                'unit_id' => ['The selected unit is unavailable.'],
            ]);
        }
    }

    private function resource(CatalogItem $item): array
    {
        $item->loadMissing(['category:id,name', 'unit:id,name,symbol']);

        return [
            'id' => $item->id,
            'name' => $item->name,
            'description' => $item->description,
            'category_id' => $item->category_id,
            'category' => $item->category?->name,
            'sku' => $item->sku,
            'barcode' => $item->barcode,
            'tracking_mode' => $item->tracking_mode,
            'unit_id' => $item->unit_id,
            'unit' => $item->unit ? [
                'id' => $item->unit->id,
                'name' => $item->unit->name,
                'symbol' => $item->unit->symbol,
            ] : null,
            'is_active' => $item->is_active,
            'update_key' => $item->update_key,
        ];
    }

    private function tenantId(): int
    {
        return $this->tenantContext->id() ?? abort(403);
    }
}
