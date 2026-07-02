<?php

namespace App\Services\PawnModule;

use App\DataObjects\RequestObjects\PawnCollateralItemCreate;
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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CollateralItemService extends BaseTenantService
{
    protected const COLLATERAL_ITEM_LIST_CACHE_TTL_SECONDS = 600;
    protected const ALLOWED_TYPES = ['Jewellery', 'Normal'];

    public function __construct(
        private CollateralItemRepository $repository,
        private TenantUserPermissionService $permissionService,
        private TenantScopedCacheKeys $tenantScopedCacheKeys,
        private TableIdGenerationService $tableIdGenerationService,
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

        $item = DB::transaction(fn () => $this->repository->create($this->buildPayload($request)));
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

            $this->repository->create([
                ...$this->buildPayload($item),
                'loan_contract_id' => $slip->id,
            ]);
        }

        $this->flushCollateralItemListCache();
    }

    public function show(int $itemId): PawnCollateralItemDetail
    {
        $this->permissionService->authorizeCollateralList();

        return PawnCollateralItemDetail::fromModel($this->findById($itemId));
    }

    public function showByCode(string $code): PawnCollateralItemDetail
    {
        $this->permissionService->authorizeCollateralList();

        return PawnCollateralItemDetail::fromModel($this->findByCode($code));
    }

    public function update(PawnCollateralItemUpdate $request): PawnCollateralItemDetail
    {
        $this->permissionService->authorizeCollateralUpdate();
        $item = $this->findById($request->itemId);
        $data = [];

        if ($request->type !== null) {
            $data['type'] = $this->normalizeType($request->type);
        }

        if($item->update_key !== $request->updateKey)
        {
            throw new AlreadyUpdatedException("This Item is already updated from different device");
        }
        $data['update_key'] = $item->update_key + 1;

        foreach ([
            'name' => 'name',
            'description' => 'description',
            'brandName' => 'brand_name',
            'imageUrl' => 'image_url',
            'estimatedValue' => 'estimated_value',
            'materialTypeId' => 'material_type_id',
            'itemCategoryTypeId' => 'item_category_type_id',
            'kyat' => 'kyat',
            'pal' => 'pal',
            'yway' => 'yway',
            'itemStatus' => 'item_status',
            'containsGemstones' => 'contains_gemstones',
            'gemstoneDetails' => 'gemstone_details',
            'quantity' => 'quantity',
            'minimumRetailPrice' => 'minimum_retail_price',
        ] as $property => $column) {
            if ($request->{$property} !== null) {
                $data[$column] = $request->{$property};
            }
        }

        if (array_key_exists('name', $data) && trim((string) $data['name']) === '') {
            throw new InvalidTenantRequest('Collateral item name is required.');
        }

        if (array_key_exists('quantity', $data) && (int) $data['quantity'] <= 0) {
            throw new InvalidTenantRequest('Collateral item quantity must be greater than zero.');
        }

        if (array_key_exists('minimum_retail_price', $data) && (float) $data['minimum_retail_price'] < 0) {
            throw new InvalidTenantRequest('Collateral item minimum retail price cannot be negative.');
        }

        if ($data === []) {
            return PawnCollateralItemDetail::fromModel($item);
        }

        $updatedItem = DB::transaction(fn () => $this->repository->updateWithLock($item, $data));
        $this->flushCollateralItemListCache();

        return PawnCollateralItemDetail::fromModel($updatedItem);
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
            'code' => $this->tableIdGenerationService->generate('pawn_collateral_items', CarbonImmutable::now()),
            'loan_contract_id' => null,
            'type' => $this->normalizeType($request->type),
            'name' => trim($request->name),
            'description' => $request->description,
            'brand_name' => $request->brandName,
            'image_url' => $request->imageUrl,
            'estimated_value' => $request->estimatedValue,
            'material_type_id' => $request->materialTypeId,
            'item_category_type_id' => $request->itemCategoryTypeId,
            'kyat' => $request->kyat,
            'pal' => $request->pal,
            'yway' => $request->yway,
            'item_status' => $request->itemStatus,
            'contains_gemstones' => $request->containsGemstones,
            'gemstone_details' => $request->gemstoneDetails,
            'quantity' => $request->quantity,
            'minimum_retail_price' => $request->minimumRetailPrice,
            'is_deleted' => false,
        ];
    }

    protected function normalizeType(string $type): string
    {
        $normalized = ucfirst(strtolower(trim($type)));

        if (! in_array($normalized, self::ALLOWED_TYPES, true)) {
            throw new InvalidTenantRequest('Collateral item type must be Jewellery or Normal.');
        }

        return $normalized;
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
