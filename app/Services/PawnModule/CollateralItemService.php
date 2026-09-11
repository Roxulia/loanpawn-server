<?php

namespace App\Services\PawnModule;

use App\DataObjects\RequestObjects\PawnCollateralItemCreate;
use App\Enums\CollateralItemType;
use App\Services\TenantModule\DefaultDataService;
use App\DataObjects\RequestObjects\PawnCollateralItemUpdate;
use App\DataObjects\ResponseObjects\CollateralItemListPage;
use App\DataObjects\ResponseObjects\PawnCollateralItemDetail;
use App\Exceptions\AlreadyUpdatedException;
use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\TenantNotFound;
use App\Models\PawnModule\PawnCollateralItem;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Repository\CollateralItemRepository;
use App\Services\BaseTenantService;
use App\Services\TableIdGenerationService;
use App\Services\TenantModule\TenantUserPermissionService;
use App\Support\TenantScopedCacheKeys;
use App\Utility\FileStorageUtility;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class CollateralItemService extends BaseTenantService
{
    protected const COLLATERAL_ITEM_LIST_CACHE_TTL_SECONDS = 600;
    protected const IMAGE_STORAGE_DISK = 'local';
    protected const IMAGE_URL_TTL_MINUTES = 5;

    public function __construct(
        private CollateralItemRepository $repository,
        private TenantUserPermissionService $permissionService,
        private TenantScopedCacheKeys $tenantScopedCacheKeys,
        private TableIdGenerationService $tableIdGenerationService,
        private FileStorageUtility $fileStorageUtility,
        private DefaultDataService $defaultDataService,
    ) {
    }

    public function list(int $perPage = 15, ?string $search = null): CollateralItemListPage
    {
        $this->permissionService->authorizeCollateralList();
        $page = $this->resolveCurrentPage();
        $search = $this->normalizeSearch($search);
        $version = $this->tenantScopedCacheKeys->currentVersion('collateral-item-list');

        return Cache::remember(
            $this->collateralItemListCacheKey($version, $page, $perPage, $search),
            now()->addSeconds(self::COLLATERAL_ITEM_LIST_CACHE_TTL_SECONDS),
            fn () => CollateralItemListPage::fromPaginator(
                $this->repository->paginate($perPage, $search)
            )
        );
    }

    public function create(PawnCollateralItemCreate $request): PawnCollateralItemDetail
    {
        $this->permissionService->authorizeCollateralCreate();
        $this->validateCreateRequest($request);

        $this->prepareImagesForCreate([$request], $this->resolveCurrentTenantId(), 'standalone');

        try {
            $item = DB::transaction(function () use ($request) {
                $item = $this->repository->create($this->buildPayload($request));
                $this->repository->saveSubItems($item, $request->subItems);
                return $this->repository->findById($item->id);
            });
        } catch (Throwable $exception) {
            $this->cleanupPreparedImages([$request]);

            throw $exception;
        }
        $this->flushCollateralItemListCache();

        return PawnCollateralItemDetail::fromModel($item);
    }

    /**
     * @param array<int, PawnCollateralItemCreate> $items
     */
    public function createForSlip(PawnLoanContractSlip $slip, array $items): void
    {
        foreach ($items as $item) {
            if (! $item instanceof PawnCollateralItemCreate) {
                throw new InvalidTenantRequest('Collateral items must be PawnCollateralItemCreate.');
            }

            $this->validateCreateRequest($item);

            $createdItem = $this->repository->create([
                ...$this->buildPayload($item),
                'loan_contract_id' => $slip->id,
            ]);
            $this->repository->saveSubItems($createdItem, $item->subItems);
        }

        $this->flushCollateralItemListCache();
    }

    /**
     * @param array<int, PawnCollateralItemCreate> $items
     */
    public function prepareImagesForCreate(array $items, int $tenantId, string $slipReference): void
    {
        try {
            foreach ($items as $item) {
                if (! $item instanceof PawnCollateralItemCreate) {
                    throw new InvalidTenantRequest('Collateral items must be PawnCollateralItemCreate.');
                }

                $item->code ??= $this->tableIdGenerationService->generateForTenant(
                    $tenantId,
                    'pawn_collateral_items',
                    CarbonImmutable::now(),
                );

                $this->validateCreateRequest($item);
                if ($item->imageReference !== null) {
                    $item->storedImagePath = $this->fileStorageUtility->uploadImage(
                        $item->imageReference,
                        $this->imageDirectory($tenantId, $slipReference, $item->code),
                        self::IMAGE_STORAGE_DISK,
                        'collateral_reference',
                    );
                }
            }
        } catch (Throwable $exception) {
            $this->cleanupPreparedImages($items);

            throw $exception;
        }
    }

    /**
     * @param array<int, PawnCollateralItemCreate> $items
     */
    public function cleanupPreparedImages(array $items): void
    {
        foreach ($items as $item) {
            if ($item instanceof PawnCollateralItemCreate) {
                $this->fileStorageUtility->deleteFile($item->storedImagePath, self::IMAGE_STORAGE_DISK);
                $item->storedImagePath = null;
            }
        }
    }

    public function show(int $itemId): PawnCollateralItemDetail
    {
        $this->permissionService->authorizeCollateralList();

        return $this->detailWithTemporaryImage($this->findById($itemId));
    }

    public function showByCode(string $code): PawnCollateralItemDetail
    {
        $this->permissionService->authorizeCollateralList();

        return $this->detailWithTemporaryImage($this->findByCode($code));
    }

    public function update(PawnCollateralItemUpdate $request): PawnCollateralItemDetail
    {
        $this->resolveCurrentTenantId();
        return $this->saveEditableCollateral($request);
    }

    protected function saveEditableCollateral(PawnCollateralItemUpdate $request): PawnCollateralItemDetail
    {
        $this->permissionService->authorizeCollateralUpdate();
        $uploadedPaths = [];
        $replacedPaths = [];
        try {
            $updated = DB::transaction(function () use ($request, &$uploadedPaths, &$replacedPaths) {
                $item = $this->repository->findByIdWithLock($request->itemId);
                if ($item === null || $item->is_deleted) {
                    throw new TenantNotFound('Collateral item not found.');
                }
                if ((int) $item->update_key !== $request->updateKey) {
                    throw new AlreadyUpdatedException('This item was updated on another device. Reload before saving.');
                }

                // Merge partial changes before validating totals, including children omitted by this request.
                $fields = $request->fields ?: $this->legacyUpdateFields($request);
                if (CollateralItemType::normalize($item->type) === CollateralItemType::Pack && (! empty($fields['image_reference']) || ! empty($fields['remove_image']))) {
                    throw new InvalidTenantRequest('Images belong to contained items, not the pack.');
                }
                $children = $fields['sub_items'] ?? [];
                unset($fields['sub_items']);
                $data = $this->editableParentFields($item, $fields);
                // Lifecycle changes remain available to existing internal callers, never the edit endpoint.
                if ($request->fields === [] && $request->itemStatus !== null) {
                    $data['item_status'] = $request->itemStatus;
                }
                $merged = array_replace($item->getAttributes(), $data);
                $rows = $this->mergePackRows($item, $children);
                $this->validateEditableValues($merged, $rows);
                $this->prepareEditedImage($fields, $data, $item->image_url, $item, $uploadedPaths, $replacedPaths);
                foreach ($rows as &$row) {
                    $this->prepareEditedImage($row, $row, $row['image_url'] ?? null, $item, $uploadedPaths, $replacedPaths);
                    unset($row['image_reference'], $row['remove_image']);
                }
                unset($row);
                $data['update_key'] = $request->updateKey + 1;
                $this->repository->saveSubItems($item, $rows);
                return $this->repository->update($item, $data);
            });
        } catch (Throwable $exception) {
            foreach ($uploadedPaths as $path) {
                $this->fileStorageUtility->deleteFile($path, self::IMAGE_STORAGE_DISK);
            }
            throw $exception;
        }
        foreach ($replacedPaths as $path) {
            $this->fileStorageUtility->deleteFile($path, self::IMAGE_STORAGE_DISK);
        }
        $this->flushCollateralItemListCache();
        $this->tenantScopedCacheKeys->bumpVersion('loan-contract-slip-list');
        return $this->detailWithTemporaryImage($updated);
    }

    protected function legacyUpdateFields(PawnCollateralItemUpdate $request): array
    {
        $data = [];
        // Retain support for existing internal callers of the typed update object.
        foreach ([
            'name' => 'name',
            'description' => 'description',
            'brandName' => 'brand_name',
            'estimatedValue' => 'estimated_value',
            'materialTypeId' => 'material_type_id',
            'itemCategoryTypeId' => 'item_category_type_id',
            'kyat' => 'kyat',
            'pal' => 'pal',
            'yway' => 'yway',
            'containsGemstones' => 'contains_gemstones',
            'gemstoneDetails' => 'gemstone_details',
            'quantity' => 'quantity',
            'minimumRetailPrice' => 'minimum_retail_price',
        ] as $property => $column) {
            if ($request->{$property} !== null) {
                $data[$column] = $request->{$property};
            }
        }

        return $data;
    }

    protected function editableParentFields(PawnCollateralItem $item, array $fields): array
    {
        $type = CollateralItemType::normalize($item->type);
        $allowed = ['name', 'quantity'];
        $allowed = array_merge($allowed, $type === CollateralItemType::Normal
            ? ['description', 'brand_name', 'item_category_type_id', 'estimated_value']
            : ['material_type_id', 'material_price_per_kyat', 'kyat', 'pal', 'yway']);
        if ($type === CollateralItemType::Jewellery) {
            $allowed = array_merge($allowed, ['description', 'contains_gemstones', 'gemstone_details']);
        }
        $data = array_intersect_key($fields, array_flip($allowed));
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }
        if ($type === CollateralItemType::Pack) {
            $data['quantity'] = 1;
        }
        $merged = array_replace($item->getAttributes(), $data);
        $merged['material_price_per_kyat'] ??= null;
        $valuationKeys = $type === CollateralItemType::Normal
            ? ['estimated_value', 'quantity']
            : ['material_type_id', 'material_price_per_kyat', 'kyat', 'pal', 'yway', 'quantity'];
        $changed = false;
        foreach ($valuationKeys as $key) {
            if (array_key_exists($key, $data) && (($data[$key] === null) !== ($item->getAttribute($key) === null) || $data[$key] != $item->getAttribute($key))) {
                $changed = true;
            }
        }
        if ($changed) {
            if ($type !== CollateralItemType::Normal && $merged['material_price_per_kyat'] === null) {
                throw new InvalidTenantRequest('Enter a material price per kyat before changing valuation inputs.');
            }
            $data['minimum_retail_price'] = $type === CollateralItemType::Normal
                ? round((float) $merged['estimated_value'] * (int) $merged['quantity'])
                : round($this->weightUnits($merged) / 12800 * (float) $merged['material_price_per_kyat'] * (int) $merged['quantity']);
        }
        if (array_key_exists('contains_gemstones', $data) && ! $data['contains_gemstones']) {
            $data['gemstone_details'] = null;
        }
        if (isset($data['minimum_retail_price']) && (! is_finite($data['minimum_retail_price']) || $data['minimum_retail_price'] < 0 || $data['minimum_retail_price'] > 999_999_999_999.99)) {
            throw new InvalidTenantRequest('Calculated collateral value is outside the supported range.');
        }
        return $data;
    }

    protected function mergePackRows(PawnCollateralItem $item, array $changes): array
    {
        if (CollateralItemType::normalize($item->type) !== CollateralItemType::Pack) {
            if ($changes !== []) {
                throw new InvalidTenantRequest('Only jewellery packs may contain sub-items.');
            }
            return [];
        }
        $rows = [];
        foreach ($item->subItems as $child) {
            $rows[$child->id] = $child->only(['id', 'name', 'quantity', 'kyat', 'pal', 'yway', 'material_type_id', 'image_url']);
        }
        $seen = [];
        $newRows = [];
        foreach ($changes as $change) {
            $id = $change['id'] ?? null;
            if ($id !== null) {
                if (! isset($rows[$id]) || isset($seen[$id])) {
                    throw new InvalidTenantRequest('Contained item ID is invalid or repeated.');
                }
                $seen[$id] = true;
                $rows[$id] = array_replace($rows[$id], $change);
            } else {
                $newRows[] = $change;
            }
        }
        return array_merge(array_values($rows), $newRows);
    }

    protected function validateEditableValues(array $parent, array &$rows): void
    {
        $isPack = CollateralItemType::normalize($parent['type']) === CollateralItemType::Pack;
        $this->validateItemValues($parent);
        if ($isPack && ($this->weightUnits($parent) <= 0 || (float) ($parent['material_price_per_kyat'] ?? 0) <= 0 || empty($parent['material_type_id']))) {
            throw new InvalidTenantRequest('A pack requires total weight, material, and a positive material price per kyat.');
        }
        $total = 0;
        foreach ($rows as &$row) {
            $row['name'] = trim((string) ($row['name'] ?? ''));
            $this->validateItemValues($row);
            $total += $this->weightUnits($row);
        }
        unset($row);
        if ($isPack && $total > $this->weightUnits($parent)) {
            throw new InvalidTenantRequest('The combined contained-item weight cannot exceed the pack total weight.');
        }
    }

    protected function validateItemValues(array $values): void
    {
        if (trim((string) ($values['name'] ?? '')) === '' || mb_strlen($values['name']) > 120) {
            throw new InvalidTenantRequest('Each collateral item requires a name of at most 120 characters.');
        }
        if (filter_var($values['quantity'] ?? 0, FILTER_VALIDATE_INT) === false || (int) ($values['quantity'] ?? 0) < 1 || (int) $values['quantity'] > 4294967295) {
            throw new InvalidTenantRequest('Item quantity must be a positive integer.');
        }
        foreach (['material_price_per_kyat', 'estimated_value', 'minimum_retail_price'] as $key) {
            if (isset($values[$key]) && (! is_numeric($values[$key]) || ! is_finite((float) $values[$key]) || (float) $values[$key] < 0 || (float) $values[$key] > 999_999_999_999.99)) {
                throw new InvalidTenantRequest('Collateral amounts must be within the supported nonnegative range.');
            }
        }
        foreach (['kyat', 'pal', 'yway'] as $key) {
            $value = $values[$key] ?? null;
            if ($value !== null && (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0 || (float) $value > 999999.99 || abs((float) $value * 100 - round((float) $value * 100)) > 0.00001)) {
                throw new InvalidTenantRequest('Weights must be nonnegative numbers with at most two decimal places.');
            }
        }
        if (isset($values['material_type_id']) && ! in_array((int) $values['material_type_id'], array_column($this->defaultDataService->getMaterialTypes(), 'id'), true)) {
            throw new InvalidTenantRequest('Material is not available to this tenant.');
        }
        if (isset($values['item_category_type_id']) && ! in_array((int) $values['item_category_type_id'], array_column($this->defaultDataService->getItemCategoryTypes(), 'id'), true)) {
            throw new InvalidTenantRequest('Item category is not available to this tenant.');
        }
    }

    protected function weightUnits(array $values): int
    {
        // Hundredths of a yway keep comparison exact at the database's two-decimal precision.
        return (int) round((float) ($values['kyat'] ?? 0) * 100) * 128
            + (int) round((float) ($values['pal'] ?? 0) * 100) * 8
            + (int) round((float) ($values['yway'] ?? 0) * 100);
    }

    protected function prepareEditedImage(array $input, array &$output, ?string $oldPath, PawnCollateralItem $parent, array &$uploaded, array &$replaced): void
    {
        if (! empty($input['image_reference']) && ! empty($input['remove_image'])) {
            throw new InvalidTenantRequest('Choose either replacing or removing an image.');
        }
        if (! empty($input['image_reference'])) {
            $output['image_url'] = $this->fileStorageUtility->uploadImage(
                $input['image_reference'],
                $this->imageDirectory($parent->tenant_id, (string) ($parent->loan_contract_id ?? 'standalone'), $parent->code),
                self::IMAGE_STORAGE_DISK,
                'collateral_reference',
            );
            $uploaded[] = $output['image_url'];
        } elseif (! empty($input['remove_image'])) {
            $output['image_url'] = null;
        } else {
            return;
        }
        if ($oldPath !== null) {
            $replaced[] = $oldPath;
        }
    }

    public function delete(int $itemId): void
    {
        $this->permissionService->authorizeCollateralDelete();
        DB::transaction(function () use ($itemId): void {
            $item = $this->repository->findByIdWithLock($itemId);

            if ($item === null) {
                throw new TenantNotFound('Collateral item not found.');
            }

            $this->repository->delete($item);
        });
        $this->flushCollateralItemListCache();
    }

    public function resolveIdByCode(string $code): int
    {
        if (ctype_digit($code)) {
            return $this->findById((int) $code)->id;
        }

        return $this->findByCode($code)->id;
    }

    public function redeemProcess(PawnLoanContractSlip $slip): void
    {
        DB::transaction(function () use ($slip): void {
            foreach ($this->repository->findByLoanContractIdWithLock($slip->id) as $item) {
                $this->repository->update($item, ['item_status' => 'redeemed']);
            }
        });
    }

    /**
     * @return PawnCollateralItemDetail[]
     */
    public function getItemsBySlip(PawnLoanContractSlip $slip): array
    {
        return $this->repository->findByLoanContractId($slip->id)
            ->map(fn (PawnCollateralItem $item): PawnCollateralItemDetail => PawnCollateralItemDetail::fromModel($item))
            ->all();
    }

    protected function findById(int $itemId): PawnCollateralItem
    {
        $item = $this->repository->findById($itemId);

        if ($item === null) {
            throw new TenantNotFound('Collateral item not found.');
        }

        return $item;
    }

    protected function findByCode(string $code): PawnCollateralItem
    {
        $item = $this->repository->findByCode($code);

        if ($item === null) {
            throw new TenantNotFound('Collateral item not found.');
        }

        return $item;
    }

    protected function validateCreateRequest(PawnCollateralItemCreate $request): void
    {
        if (trim($request->name) === '') {
            throw new InvalidTenantRequest('Collateral item name is required.');
        }

        $this->normalizeType($request->type);

        // Initial pack contents contain identification only; detailed measurements are entered later.
        if ($this->normalizeType($request->type) === CollateralItemType::Pack->value) {
            $request->quantity = 1;
            $request->estimatedValue = 0;
            $request->description = $request->brandName = null;
            $request->imageReference = null;
            $request->itemCategoryTypeId = null;
            $request->containsGemstones = false;
            $request->gemstoneDetails = null;
            $request->subItems = array_map(fn (array $row) => [
                'name' => trim((string) ($row['name'] ?? '')),
                'quantity' => $row['quantity'] ?? 0,
            ], $request->subItems);
            if ($this->calculateMinimumRetailPrice($request) > 999_999_999_999.99) {
                throw new InvalidTenantRequest('Calculated collateral value is outside the supported range.');
            }
            $this->validateEditableValues([
                'type' => CollateralItemType::Pack->value, 'name' => $request->name,
                'quantity' => 1, 'kyat' => $request->kyat, 'pal' => $request->pal, 'yway' => $request->yway,
                'material_type_id' => $request->materialTypeId, 'material_price_per_kyat' => $request->materialPricePerKyat,
            ], $request->subItems);
        } elseif ($request->subItems !== []) {
            throw new InvalidTenantRequest('Only jewellery packs may contain sub-items.');
        }

        if ($request->quantity <= 0) {
            throw new InvalidTenantRequest('Collateral item quantity must be greater than zero.');
        }

        if ($request->minimumRetailPrice < 0) {
            throw new InvalidTenantRequest('Collateral item minimum retail price cannot be negative.');
        }
    }

    protected function buildPayload(PawnCollateralItemCreate $request): array
    {
        return [
            'tenant_id' => $this->resolveCurrentTenantId(),
            'code' => $request->code
                ?? $this->tableIdGenerationService->generate('pawn_collateral_items', CarbonImmutable::now()),
            'loan_contract_id' => null,
            'type' => $this->normalizeType($request->type),
            'name' => trim($request->name),
            'description' => $request->description,
            'brand_name' => $request->brandName,
            'image_url' => $request->storedImagePath,
            'estimated_value' => $request->estimatedValue,
            'material_type_id' => $request->materialTypeId,
            'material_price_per_kyat' => $this->normalizeType($request->type) === CollateralItemType::Normal->value ? null : $request->materialPricePerKyat,
            'item_category_type_id' => $request->itemCategoryTypeId,
            'kyat' => $request->kyat,
            'pal' => $request->pal,
            'yway' => $request->yway,
            'item_status' => $request->itemStatus,
            'contains_gemstones' => $request->containsGemstones,
            'gemstone_details' => $request->gemstoneDetails,
            'quantity' => $request->quantity,
            'minimum_retail_price' => $this->calculateMinimumRetailPrice($request),
            'is_deleted' => false,
        ];
    }

    protected function calculateMinimumRetailPrice(PawnCollateralItemCreate $request): float
    {
        if ($this->normalizeType($request->type) === CollateralItemType::Normal->value) {
            return $request->minimumRetailPrice;
        }

        $weightInKyat = $request->kyat + ($request->pal / 16) + ($request->yway / 128);

        return (float) round($request->materialPricePerKyat * $weightInKyat * ($this->normalizeType($request->type) === CollateralItemType::Pack->value ? 1 : $request->quantity));
    }

    protected function detailWithTemporaryImage(PawnCollateralItem $item): PawnCollateralItemDetail
    {
        $expiration = now()->addMinutes(self::IMAGE_URL_TTL_MINUTES);
        $detail = PawnCollateralItemDetail::fromModel($item);
        if ($this->fileStorageUtility->fileExists($item->image_url, self::IMAGE_STORAGE_DISK)) {
            $detail->imageUrl = $this->fileStorageUtility->getTemporaryFileUrl($item->image_url, $expiration, self::IMAGE_STORAGE_DISK);
            $detail->imageUrlExpiresAt = $expiration->toISOString();
        }
        foreach ($item->subItems as $index => $child) {
            if ($this->fileStorageUtility->fileExists($child->image_url, self::IMAGE_STORAGE_DISK)) {
                $detail->subItems[$index]->imageUrl = $this->fileStorageUtility->getTemporaryFileUrl($child->image_url, $expiration, self::IMAGE_STORAGE_DISK);
                $detail->subItems[$index]->imageUrlExpiresAt = $expiration->toISOString();
            }
        }
        return $detail;
    }

    protected function imageDirectory(int $tenantId, string $slipReference, string $itemCode): string
    {
        return "tenant-collateral/{$tenantId}/slips/{$slipReference}/{$itemCode}";
    }

    protected function normalizeType(string $type): string
    {
        $normalized = CollateralItemType::normalize($type);
        if ($normalized === null) {
            throw new InvalidTenantRequest('Collateral item type must be Jewellery, Normal, or Pack of Jewellery.');
        }

        return $normalized->value;
    }

    protected function flushCollateralItemListCache(): void
    {
        $this->tenantScopedCacheKeys->bumpVersion('collateral-item-list');
    }

    protected function resolveCurrentPage(): int
    {
        return max(1, (int) request()->query('page', 1));
    }

    protected function normalizeSearch(?string $search): ?string
    {
        if ($search === null) {
            return null;
        }

        $search = trim($search);

        return $search === '' ? null : $search;
    }

    protected function collateralItemListCacheKey(int $version, int $page, int $perPage, ?string $search): string
    {
        $key = $this->tenantScopedCacheKeys->paginatedListKey('collateral-item-list', $version, $page, $perPage);

        if ($search === null) {
            return $key;
        }

        return $key . ':search:' . sha1(mb_strtolower($search));
    }
}
