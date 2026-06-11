<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\TenantCustomerCreate;
use App\DataObjects\RequestObjects\TenantCustomerUpdate;
use App\DataObjects\ResponseObjects\TenantCustomerDetail;
use App\DataObjects\ResponseObjects\TenantCustomerListPage;
use App\DataObjects\ResponseObjects\TenantCustomerUpsertResult;
use App\Exceptions\DuplicateValueFound;
use App\Exceptions\TenantNotFound;
use App\Models\CoreModule\TenantCustomer;
use App\Repository\TenantCustomerRepository;
use App\Services\BaseTenantService;
use App\Services\TableIdGenerationService;
use App\Support\TenantScopedCacheKeys;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Exceptions\AlreadyUpdatedException;

class TenantCustomerService extends BaseTenantService
{
    protected const TENANT_CUSTOMER_LIST_CACHE_TTL_SECONDS = 600;

    public function __construct(
        private TenantCustomerRepository $repository,
        private TenantAuditLogService $tenantAuditLogService,
        private TenantUserPermissionService $permissionService,
        private TenantScopedCacheKeys $tenantScopedCacheKeys,
        private TableIdGenerationService $tableIdGenerationService,
    ) {
    }

    public function list(int $perPage = 15, ?string $search = null): TenantCustomerListPage
    {
        $this->permissionService->authorizeCustomerList();
        $page = $this->resolveCurrentPage();
        $search = $this->normalizeSearch($search);
        $version = $this->tenantScopedCacheKeys->currentVersion('tenant-customer-list');

        return Cache::remember(
            $this->tenantCustomerListCacheKey($version, $page, $perPage, $search),
            now()->addSeconds(self::TENANT_CUSTOMER_LIST_CACHE_TTL_SECONDS),
            fn () => TenantCustomerListPage::fromPaginator(
                $this->repository->paginate($perPage, $search)
            )
        );
    }

    public function createForCurrentTenant(TenantCustomerCreate $request): TenantCustomerUpsertResult
    {
        $this->permissionService->authorizeCustomerCreate();

        $request->createdBy = $this->resolveCurrentTenantUserId();

        $duplicate = $this->repository->findDuplicateForCreate(
            app(\App\Support\TenantContext::class)->id(),
            $request->email,
            $request->phone,
            $request->nrc
        );

        if ($duplicate !== null) {
            return TenantCustomerUpsertResult::existing(TenantCustomerDetail::fromModel($duplicate));
        }

        $customer = DB::transaction(function () use ($request) {
            $customer = $this->repository->create([
                'code' => $this->tableIdGenerationService->generate('tenant_customers', CarbonImmutable::now()),
                'name' => $request->name,
                'email' => $request->email,
                'nrc' => $request->nrc,
                'phone' => $request->phone,
                'address' => $request->address,
                'trust_score' => $request->trustScore,
                'note' => $request->note,
                'created_by' => $request->createdBy,
                'is_deleted' => false,
            ]);

            $this->tenantAuditLogService->log(
                'tenant_customer.created',
                TenantCustomer::class,
                $customer->id,
                [
                    'customer' => $customer->only([
                        'name',
                        'nrc',
                        'email',
                        'phone',
                        'address',
                        'trust_score',
                        'note',
                    ]),
                ]
            );

            return $customer;
        });

        $this->flushTenantCustomerListCache();

        return TenantCustomerUpsertResult::created(TenantCustomerDetail::fromModel($customer));
    }

    public function createCustomer(TenantCustomerCreate $request): TenantCustomerUpsertResult
    {
        return $this->createForCurrentTenant($request);
    }

    public function show(int $customerId): TenantCustomerDetail
    {
        $this->permissionService->authorizeCustomerList();

        return TenantCustomerDetail::fromModel($this->findCustomerForCurrentTenant($customerId));
    }

    public function showByCode(string $code): TenantCustomerDetail
    {
        $this->permissionService->authorizeCustomerList();

        return TenantCustomerDetail::fromModel($this->findCustomerForCurrentTenantByCode($code));
    }

    public function update(TenantCustomerUpdate $request): TenantCustomerDetail
    {
        $this->permissionService->authorizeCustomerUpdate();
        $customer = $this->findCustomerForCurrentTenant($request->customerId);

        if($customer->update_key !== $request->updateKey)
        {
            throw new AlreadyUpdatedException("This item is already Updated.Please refresh");
        }
        $data = [];

        if ($request->name !== null) {
            $data['name'] = $request->name;
        }

        if ($request->email !== null && $request->email !== $customer->email) {
            if ($this->repository->existsByField('email', $request->email, $customer->id)) {
                throw new DuplicateValueFound('Tenant customer email already exists.');
            }

            $data['email'] = $request->email;
        }

        if ($request->nrc !== null && $request->nrc !== $customer->nrc) {
            if ($this->repository->existsByField('nrc', $request->nrc, $customer->id)) {
                throw new DuplicateValueFound('Tenant customer NRC already exists.');
            }

            $data['nrc'] = $request->nrc;
        }

        if ($request->phone !== null && $request->phone !== $customer->phone) {
            if ($this->repository->existsByField('phone', $request->phone, $customer->id)) {
                throw new DuplicateValueFound('Tenant customer phone already exists.');
            }

            $data['phone'] = $request->phone;
        }

        if ($request->address !== null) {
            $data['address'] = $request->address;
        }

        if ($request->trustScore !== null) {
            $data['trust_score'] = $request->trustScore;
        }

        if ($request->note !== null) {
            $data['note'] = $request->note;
        }

        if ($data === []) {
            return TenantCustomerDetail::fromModel($customer);
        }

        $data['update_key'] = $customer->updateKey+1;

        $original = $customer->only(array_keys($data));

        $updatedCustomer = DB::transaction(function () use ($customer, $data, $original) {

            $updatedCustomer = $this->repository->updateWithLock($customer, $data);

            $this->tenantAuditLogService->log(
                'tenant_customer.updated',
                TenantCustomer::class,
                $updatedCustomer->id,
                [
                    'before' => $original,
                    'after' => $updatedCustomer->only(array_keys($data)),
                ]
            );

            return $updatedCustomer;
        });

        $this->flushTenantCustomerListCache();

        return TenantCustomerDetail::fromModel($updatedCustomer);
    }

    public function delete(int $customerId): void
    {
        $this->permissionService->authorizeCustomerDelete();
        $customer = $this->findCustomerForCurrentTenant($customerId);

        DB::transaction(function () use ($customer) {
            $customer = $this->repository->findByIdWithLock($customer->id);

            if ($customer === null) {
                throw new TenantNotFound('Tenant customer not found.');
            }

            $this->tenantAuditLogService->log(
                'tenant_customer.deleted',
                TenantCustomer::class,
                $customer->id,
                [
                    'customer' => $customer->only([
                        'name',
                        'email',
                        'phone',
                        'address',
                        'trust_score',
                        'note',
                    ]),
                ]
            );

            $this->repository->update($customer, [
                'is_deleted' => true,
                'update_key' => $customer->update_key+1
            ]);
            $customer->delete();
        });

        $this->flushTenantCustomerListCache();
    }

    public function resolveIdByCode(string $code): int
    {
        if (ctype_digit($code)) {
            return $this->findCustomerForCurrentTenant((int) $code)->id;
        }

        return $this->findCustomerForCurrentTenantByCode($code)->id;
    }

    protected function resolveCurrentTenantUserId(): ?int
    {
        $tenantUser = Auth::guard('tenantuser')->user();

        return $tenantUser?->id;
    }

    protected function findCustomerForCurrentTenant(int $customerId): TenantCustomer
    {
        $customer = $this->repository->findById($customerId);

        if ($customer === null) {
            throw new TenantNotFound('Tenant customer not found.');
        }

        return $customer;
    }

    protected function findCustomerForCurrentTenantByCode(string $code): TenantCustomer
    {
        $customer = $this->repository->findByCode($code);

        if ($customer === null) {
            throw new TenantNotFound('Tenant customer not found.');
        }

        return $customer;
    }

    protected function flushTenantCustomerListCache(): void
    {
        $this->tenantScopedCacheKeys->bumpVersion('tenant-customer-list');
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

    protected function tenantCustomerListCacheKey(int $version, int $page, int $perPage, ?string $search): string
    {
        $key = $this->tenantScopedCacheKeys->paginatedListKey('tenant-customer-list', $version, $page, $perPage);

        if ($search === null) {
            return $key;
        }

        return $key . ':search:' . sha1(mb_strtolower($search));
    }

}
