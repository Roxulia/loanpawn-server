<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\TenantCustomerCreate;
use App\DataObjects\RequestObjects\TenantCustomerUpdate;
use App\DataObjects\ResponseObjects\TenantCustomerDetail;
use App\DataObjects\ResponseObjects\TenantCustomerLastActivity;
use App\DataObjects\ResponseObjects\TenantCustomerListPage;
use App\DataObjects\ResponseObjects\TenantCustomerListSummary;
use App\DataObjects\ResponseObjects\TenantCustomerUpsertResult;
use App\Exceptions\AlreadyUpdatedException;
use App\Exceptions\DuplicateValueFound;
use App\Exceptions\TenantNotFound;
use App\Models\CoreModule\TenantCustomer;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Repository\TenantCustomerRepository;
use App\Services\BaseTenantService;
use App\Services\TableIdGenerationService;
use App\Support\TenantScopedCacheKeys;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class TenantCustomerService extends BaseTenantService
{
    protected const RISK_TRUST_SCORE_THRESHOLD = 102;

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
        $search = $this->normalizeSearch($search);
        $today = CarbonImmutable::today();
        $paginator = $this->repository->paginate($perPage, $search);
        $customerIds = collect($paginator->items())->pluck('id')->all();
        $activitiesByCustomerId = $this->mapLastActivities(
            $this->repository->latestSlipsForCustomerIds($customerIds),
            $today,
        );
        $summary = $this->repository->customerListSummary($today, self::RISK_TRUST_SCORE_THRESHOLD);

        return TenantCustomerListPage::fromPaginator(
            $paginator,
            new TenantCustomerListSummary(
                totalClients: (int) $summary['totalClients'],
                averageTrustScore: $this->normalizeAverageTrustScore((float) $summary['averageTrustScore']),
                activePawnLoans: (int) $summary['activePawnLoans'],
                riskFlagged: (int) $summary['riskFlagged'],
            ),
            $activitiesByCustomerId,
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
        $customer = $this->findCustomerForCurrentTenant($customerId);

        return TenantCustomerDetail::fromModelWithDetail(
            $customer,
            $this->repository->customerSlipMetrics($customer->id),
            $this->repository->activeSlipsForCustomer($customer->id)
        );
    }

    public function showByCode(string $code): TenantCustomerDetail
    {
        $this->permissionService->authorizeCustomerList();
        $customer = $this->findCustomerForCurrentTenantByCode($code);

        return TenantCustomerDetail::fromModelWithDetail(
            $customer,
            $this->repository->customerSlipMetrics($customer->id),
            $this->repository->activeSlipsForCustomer($customer->id)
        );
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

    protected function normalizeSearch(?string $search): ?string
    {
        if ($search === null) {
            return null;
        }

        $search = trim($search);

        return $search === '' ? null : $search;
    }

    protected function mapLastActivities(iterable $slipsByCustomerId, CarbonImmutable $today): Collection
    {
        return collect($slipsByCustomerId)
            ->map(fn (PawnLoanContractSlip $slip) => $this->mapLastActivity($slip, $today));
    }

    protected function mapLastActivity(PawnLoanContractSlip $slip, CarbonImmutable $today): TenantCustomerLastActivity
    {
        $status = mb_strtolower((string) $slip->status);
        $date = $slip->created_at?->toDateString();
        $expireDate = $slip->expire_at;

        if ($status === 'active' && $expireDate !== null && $expireDate->lt($today)) {
            return new TenantCustomerLastActivity(
                date: $date,
                status: 'PAYMENT DELINQUENT',
                label: 'Active slip past expiry',
                tone: 'danger',
            );
        }

        if ($status === 'active') {
            return new TenantCustomerLastActivity(
                date: $date,
                status: 'COLLATERAL VERIFIED',
                label: 'Active pawn loan in good standing',
                tone: 'success',
            );
        }

        if ($status === 'redeemed') {
            return new TenantCustomerLastActivity(
                date: $date,
                status: 'REDEEMED',
                label: 'Pawn loan closed',
                tone: 'success',
            );
        }

        if ($status === 'expired') {
            return new TenantCustomerLastActivity(
                date: $date,
                status: 'PAYMENT DELINQUENT',
                label: 'Expired pawn loan requires review',
                tone: 'danger',
            );
        }

        return new TenantCustomerLastActivity(
            date: $date,
            status: mb_strtoupper((string) $slip->status),
            label: 'Latest pawn activity recorded',
            tone: 'neutral',
        );
    }

    protected function normalizeAverageTrustScore(float $trustScore): float
    {
        return round(max(0, min(100, ($trustScore / 255) * 100)), 1);
    }

}
