<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\TenantExpenseCreate;
use App\DataObjects\RequestObjects\TenantExpenseUpdate;
use App\DataObjects\ResponseObjects\TenantExpenseDetail;
use App\DataObjects\ResponseObjects\TenantExpenseListPage;
use App\Exceptions\AlreadyUpdatedException;
use App\Exceptions\TenantNotFound;
use App\Models\CoreModule\TenantExpense;
use App\Repository\TenantExpenseRepository;
use App\Services\BaseTenantService;
use App\Services\TableIdGenerationService;
use App\Support\TenantScopedCacheKeys;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class TenantExpenseService extends BaseTenantService
{
    protected const TENANT_EXPENSE_LIST_CACHE_TTL_SECONDS = 600;

    public function __construct(
        private TenantExpenseRepository $repository,
        private TenantAccountingService $tenantAccountingService,
        private TenantAuditLogService $tenantAuditLogService,
        private TenantUserPermissionService $permissionService,
        private TenantScopedCacheKeys $tenantScopedCacheKeys,
        private TableIdGenerationService $tableIdGenerationService,
        private TenantIdempotencyService $tenantIdempotencyService,
    ) {
    }

    public function list(int $perPage = 15): TenantExpenseListPage
    {
        $this->permissionService->authorizeExpenseList();
        $page = $this->resolveCurrentPage();
        $version = $this->tenantScopedCacheKeys->currentVersion('tenant-expense-list');

        return Cache::remember(
            $this->tenantScopedCacheKeys->paginatedListKey('tenant-expense-list', $version, $page, $perPage),
            now()->addSeconds(self::TENANT_EXPENSE_LIST_CACHE_TTL_SECONDS),
            fn () => TenantExpenseListPage::fromPaginator($this->repository->paginate($perPage))
        );
    }

    public function createForCurrentTenant(TenantExpenseCreate $request): TenantExpenseDetail
    {
        $this->permissionService->authorizeExpenseCreate();
        $request->tenantId = $this->resolveCurrentTenantId();
        $request->createdBy = $request->createdBy ?? $this->resolveCurrentTenantUserId();

        $idempotencyRecord = $this->tenantIdempotencyService->reserveOptional(
            'tenant_expense.create',
            $request->idempotencyKey,
            $this->tenantExpenseCreateIdempotencyPayload($request)
        );

        if ($idempotencyRecord !== null && $this->tenantIdempotencyService->isReplay($idempotencyRecord)) {
            $this->tenantIdempotencyService->replay($idempotencyRecord);
        }

        try {
            $expense = DB::transaction(function () use ($request) {
                $expense = $this->repository->create([
                    'tenant_id' => $request->tenantId,
                    'code' => $this->tableIdGenerationService->generate('tenant_expenses', CarbonImmutable::now()),
                    'description' => $request->description,
                    'amount' => $request->amount,
                    'expense_type_id' => $request->expenseTypeId,
                    'created_by' => $request->createdBy,
                ]);

                $this->tenantAccountingService->createOutgoingForReference(
                    $expense,
                    $expense->description,
                    (float) $expense->amount,
                    $expense->created_by
                );

                $this->tenantAuditLogService->log(
                    'tenant_expense.created',
                    TenantExpense::class,
                    $expense->id,
                    [
                        'expense' => $expense->only([
                            'description',
                            'amount',
                            'expense_type_id',
                        ]),
                    ]
                );

                return $expense;
            });

            $this->flushTenantExpenseListCache();
            $detail = TenantExpenseDetail::fromModel($expense);

            if ($idempotencyRecord !== null) {
                $this->tenantIdempotencyService->markCompleted(
                    $idempotencyRecord,
                    201,
                    [
                        'message' => 'Expense created successfully.',
                        'data' => $detail->toArray(),
                    ],
                    TenantExpense::class,
                    $expense->id
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

    public function update(TenantExpenseUpdate $request): TenantExpenseDetail
    {
        $this->permissionService->authorizeExpenseUpdate();
        $expense = $this->findExpenseForCurrentTenant($request->expenseId);
        $data = [];

        if($request->updateKey !== $expense->update_key)
        {
            throw new AlreadyUpdatedException("This item is already updated.Please Refresh");
        }

        if ($request->description !== null) {
            $data['description'] = $request->description;
        }

        if ($request->amount !== null) {
            $data['amount'] = $request->amount;
        }

        if ($request->expenseTypeId !== null) {
            $data['expense_type_id'] = $request->expenseTypeId;
        }

        if ($data === []) {
            return TenantExpenseDetail::fromModel($expense);
        }
        $data['update_key'] = $expense->updateKey+1;

        $original = $expense->only(array_keys($data));

        $updatedExpense = DB::transaction(function () use ($expense, $data, $original) {
            $updatedExpense = $this->repository->updateWithLock($expense, $data);

            $this->tenantAccountingService->syncOutgoingForReference(
                $updatedExpense,
                $updatedExpense->description,
                (float) $updatedExpense->amount
            );

            $this->tenantAuditLogService->log(
                'tenant_expense.updated',
                TenantExpense::class,
                $updatedExpense->id,
                [
                    'before' => $original,
                    'after' => $updatedExpense->only(array_keys($data)),
                ]
            );

            return $updatedExpense;
        });

        $this->flushTenantExpenseListCache();

        return TenantExpenseDetail::fromModel($updatedExpense);
    }

    public function resolveIdByCode(string $code): int
    {
        if (ctype_digit($code)) {
            return $this->findExpenseForCurrentTenant((int) $code)->id;
        }

        return $this->findExpenseForCurrentTenantByCode($code)->id;
    }

    public function delete(int $expenseId): void
    {
        $this->permissionService->authorizeExpenseDelete();
        $expense = $this->findExpenseForCurrentTenant($expenseId);

        DB::transaction(function () use ($expense) {
            $expense = $this->repository->findByIdWithLock($expense->id);

            if ($expense === null) {
                throw new TenantNotFound('Tenant expense not found.');
            }

            $this->tenantAuditLogService->log(
                'tenant_expense.deleted',
                TenantExpense::class,
                $expense->id,
                [
                    'expense' => $expense->only([
                        'description',
                        'amount',
                        'expense_type_id',
                    ]),
                ]
            );

            $this->tenantAccountingService->deleteForReference($expense);
            $this->repository->delete($expense);
        });

        $this->flushTenantExpenseListCache();
    }

    protected function findExpenseForCurrentTenant(int $expenseId): TenantExpense
    {
        $expense = $this->repository->findById($expenseId);

        if ($expense === null) {
            throw new TenantNotFound('Tenant expense not found.');
        }

        return $expense;
    }

    protected function findExpenseForCurrentTenantByCode(string $code): TenantExpense
    {
        $expense = $this->repository->findByCode($code);

        if ($expense === null) {
            throw new TenantNotFound('Tenant expense not found.');
        }

        return $expense;
    }

    protected function resolveCurrentTenantUserId(): ?int
    {
        return Auth::guard('tenantuser')->id();
    }

    protected function flushTenantExpenseListCache(): void
    {
        $this->tenantScopedCacheKeys->bumpVersion('tenant-expense-list');
    }

    protected function tenantExpenseCreateIdempotencyPayload(TenantExpenseCreate $request): array
    {
        return [
            'description' => $request->description,
            'amount' => $request->amount,
            'expense_type_id' => $request->expenseTypeId,
            'created_by' => $request->createdBy,
        ];
    }

    protected function resolveCurrentPage(): int
    {
        return max(1, (int) request()->query('page', 1));
    }
}
