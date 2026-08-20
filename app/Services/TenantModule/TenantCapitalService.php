<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\TenantCapitalCreate;
use App\DataObjects\RequestObjects\TenantCapitalUpdate;
use App\DataObjects\ResponseObjects\TenantCapitalDetail;
use App\DataObjects\ResponseObjects\TenantCapitalListPage;
use App\Exceptions\AlreadyUpdatedException;
use App\Exceptions\TenantNotFound;
use App\Models\CoreModule\TenantCapital;
use App\Repository\TenantCapitalRepository;
use App\Services\BaseTenantService;
use App\Services\TableIdGenerationService;
use App\Services\TenantModule\Accounting\FinancialAccountTransactionService;
use App\Services\TenantModule\Accounting\MultiAccountManagement;
use App\Support\TenantScopedCacheKeys;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class TenantCapitalService extends BaseTenantService
{
    protected const TENANT_CAPITAL_LIST_CACHE_TTL_SECONDS = 600;

    public function __construct(
        private TenantCapitalRepository $repository,
        private TenantAccountingTransactionService $tenantAccountingService,
        private TenantAuditLogService $tenantAuditLogService,
        private TenantUserPermissionService $permissionService,
        private TenantScopedCacheKeys $tenantScopedCacheKeys,
        private TableIdGenerationService $tableIdGenerationService,
        private TenantIdempotencyService $tenantIdempotencyService,
        private MultiAccountManagement $multiAccountManagement,
        private FinancialAccountTransactionService $financialAccountTransactionService,
    ) {}

    public function list(int $perPage = 15): TenantCapitalListPage
    {
        $this->permissionService->authorizeCapitalList();
        $page = $this->resolveCurrentPage();
        $version = $this->tenantScopedCacheKeys->currentVersion('tenant-capital-list');

        return Cache::remember(
            $this->tenantScopedCacheKeys->paginatedListKey('tenant-capital-list', $version, $page, $perPage),
            now()->addSeconds(self::TENANT_CAPITAL_LIST_CACHE_TTL_SECONDS),
            fn () => TenantCapitalListPage::fromPaginator($this->repository->paginate($perPage))
        );
    }

    public function createForCurrentTenant(TenantCapitalCreate $request): TenantCapitalDetail
    {
        $this->permissionService->authorizeCapitalCreate();
        $request->tenantId = $this->resolveCurrentTenantId();
        $request->createdBy = $request->createdBy ?? $this->resolveCurrentTenantUserId();
        $financialAccount = $this->multiAccountManagement->findActiveCurrentTenantAccount($request->accountId);

        $idempotencyRecord = $this->tenantIdempotencyService->reserveOptional(
            'tenant_capital.create',
            $request->idempotencyKey,
            $this->tenantCapitalCreateIdempotencyPayload($request)
        );

        if ($idempotencyRecord !== null && $this->tenantIdempotencyService->isReplay($idempotencyRecord)) {
            $this->tenantIdempotencyService->replay($idempotencyRecord);
        }

        try {
            $capital = DB::transaction(function () use ($request, $financialAccount) {
                $capital = $this->repository->create([
                    'tenant_id' => $request->tenantId,
                    'code' => $this->tableIdGenerationService->generate('tenant_capitals', CarbonImmutable::now()),
                    'account_id' => $financialAccount->id,
                    'description' => $request->description,
                    'amount' => $request->amount,
                    'created_by' => $request->createdBy,
                ]);

                $accountingTransaction = $this->tenantAccountingService->recordCapitalCreation(
                    $capital,
                    $capital->description,
                    (float) $capital->amount,
                    $financialAccount->currency,
                    $capital->created_by,
                    $request->reportingExchangeRate,
                );
                $this->financialAccountTransactionService->recordCapitalContribution(
                    $financialAccount,
                    (float) $capital->amount,
                    $capital->code,
                    TenantCapital::class,
                    $capital->description,
                    $capital->created_by,
                    $accountingTransaction->id,
                );

                $this->tenantAuditLogService->log(
                    'tenant_capital.created',
                    TenantCapital::class,
                    $capital->id,
                    [
                        'capital' => $capital->only([
                            'description',
                            'amount',
                        ]),
                    ]
                );

                return $capital;
            });

            $this->flushTenantCapitalListCache();
            $detail = TenantCapitalDetail::fromModel($capital);

            if ($idempotencyRecord !== null) {
                $this->tenantIdempotencyService->markCompleted(
                    $idempotencyRecord,
                    201,
                    [
                        'message' => 'Capital created successfully.',
                        'data' => $detail->toArray(),
                    ],
                    TenantCapital::class,
                    $capital->id
                );
            }

            return $detail;
        } catch (Throwable $exception) {
            if ($idempotencyRecord !== null) {
                $this->tenantIdempotencyService->markFailed($idempotencyRecord);
            }

            throw $exception;
        }
    }

    public function update(TenantCapitalUpdate $request): TenantCapitalDetail
    {
        $this->permissionService->authorizeCapitalUpdate();
        $capital = $this->findCapitalForCurrentTenant($request->capitalId);
        $financialAccount = $this->multiAccountManagement->resolvePostedTransactionAccount(
            $capital->account_id,
            $request->accountId,
        );
        $data = [];

        if ($request->updateKey !== $capital->update_key) {
            throw new AlreadyUpdatedException('This item is already updated.Please Refresh');
        }

        if ($request->description !== null) {
            $data['description'] = $request->description;
        }

        if ($request->amount !== null) {
            $data['amount'] = $request->amount;
        }

        if ($data === []) {
            return TenantCapitalDetail::fromModel($capital);
        }

        $data['update_key'] = $capital->update_key + 1;
        $original = $capital->only(array_keys($data));

        $updatedCapital = DB::transaction(function () use ($capital, $data, $original, $financialAccount, $request) {
            $this->financialAccountTransactionService->reverseReference($financialAccount, $capital->code, TenantCapital::class, $this->resolveCurrentTenantUserId());
            $updatedCapital = $this->repository->updateWithLock($capital, $data);

            $this->tenantAccountingService->syncCapital(
                $updatedCapital,
                $updatedCapital->description,
                (float) $updatedCapital->amount,
                $financialAccount->currency,
                $request->reportingExchangeRate,
            );
            $this->financialAccountTransactionService->recordCapitalContribution($financialAccount, (float) $updatedCapital->amount, $updatedCapital->code, TenantCapital::class, $updatedCapital->description, $this->resolveCurrentTenantUserId());

            $this->tenantAuditLogService->log(
                'tenant_capital.updated',
                TenantCapital::class,
                $updatedCapital->id,
                [
                    'before' => $original,
                    'after' => $updatedCapital->only(array_keys($data)),
                ]
            );

            return $updatedCapital;
        });

        $this->flushTenantCapitalListCache();

        return TenantCapitalDetail::fromModel($updatedCapital);
    }

    public function resolveIdByCode(string $code): int
    {
        if (ctype_digit($code)) {
            return $this->findCapitalForCurrentTenant((int) $code)->id;
        }

        return $this->findCapitalForCurrentTenantByCode($code)->id;
    }

    public function show(string $code): TenantCapitalDetail
    {
        $this->permissionService->authorizeCapitalList();

        return TenantCapitalDetail::fromModel($this->findCapitalForCurrentTenantByCode($code));
    }

    public function delete(int $capitalId): void
    {
        $this->permissionService->authorizeCapitalDelete();
        $capital = $this->findCapitalForCurrentTenant($capitalId);

        DB::transaction(function () use ($capital) {
            $capital = $this->repository->findByIdWithLock($capital->id);

            if ($capital === null) {
                throw new TenantNotFound('Tenant capital not found.');
            }

            $this->tenantAuditLogService->log(
                'tenant_capital.deleted',
                TenantCapital::class,
                $capital->id,
                [
                    'capital' => $capital->only([
                        'description',
                        'amount',
                    ]),
                ]
            );

            $this->tenantAccountingService->deleteForReference($capital);
            $account = $capital->account_id === null
                ? $this->multiAccountManagement->findActiveCurrentTenantAccount()
                : $this->multiAccountManagement->findCurrentTenantAccountById((int) $capital->account_id);
            $this->financialAccountTransactionService->reverseReference($account, $capital->code, TenantCapital::class, $this->resolveCurrentTenantUserId());
            $this->repository->delete($capital);
        });

        $this->flushTenantCapitalListCache();
    }

    protected function findCapitalForCurrentTenant(int $capitalId): TenantCapital
    {
        $capital = $this->repository->findById($capitalId);

        if ($capital === null) {
            throw new TenantNotFound('Tenant capital not found.');
        }

        return $capital;
    }

    protected function findCapitalForCurrentTenantByCode(string $code): TenantCapital
    {
        $capital = $this->repository->findByCode($code);

        if ($capital === null) {
            throw new TenantNotFound('Tenant capital not found.');
        }

        return $capital;
    }

    protected function resolveCurrentTenantUserId(): ?int
    {
        return Auth::guard('tenantuser')->id();
    }

    protected function flushTenantCapitalListCache(): void
    {
        $this->tenantScopedCacheKeys->bumpVersion('tenant-capital-list');
    }

    protected function tenantCapitalCreateIdempotencyPayload(TenantCapitalCreate $request): array
    {
        return [
            'description' => $request->description,
            'amount' => $request->amount,
            'account_id' => $request->accountId,
            'created_by' => $request->createdBy,
        ];
    }

    protected function resolveCurrentPage(): int
    {
        return max(1, (int) request()->query('page', 1));
    }
}
