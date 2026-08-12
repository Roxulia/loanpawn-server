<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\TenantExpenseCreate;
use App\DataObjects\RequestObjects\TenantExpenseUpdate;
use App\DataObjects\ResponseObjects\TenantExpenseDetail;
use App\DataObjects\ResponseObjects\TenantExpenseFullDetail;
use App\DataObjects\ResponseObjects\TenantExpenseListPage;
use App\Exceptions\AlreadyUpdatedException;
use App\Exceptions\TenantNotFound;
use App\Models\CoreModule\TenantExpense;
use App\Repository\TenantExpenseRepository;
use App\Services\BaseTenantService;
use App\Services\TableIdGenerationService;
use App\Services\TenantModule\Accounting\FinancialAccountTransactionService;
use App\Services\TenantModule\Accounting\MultiAccountManagement;
use App\Support\TenantScopedCacheKeys;
use App\Utility\FileStorageUtility;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class TenantExpenseService extends BaseTenantService
{
    protected const TENANT_EXPENSE_LIST_CACHE_TTL_SECONDS = 600;

    protected const IMAGE_STORAGE_DISK = 'local';

    protected const IMAGE_URL_TTL_MINUTES = 5;

    public function __construct(
        private TenantExpenseRepository $repository,
        private TenantAccountingTransactionService $tenantAccountingService,
        private TenantAuditLogService $tenantAuditLogService,
        private TenantUserPermissionService $permissionService,
        private TenantScopedCacheKeys $tenantScopedCacheKeys,
        private TableIdGenerationService $tableIdGenerationService,
        private TenantIdempotencyService $tenantIdempotencyService,
        private FileStorageUtility $fileStorageUtility,
        private MultiAccountManagement $multiAccountManagement,
        private FinancialAccountTransactionService $financialAccountTransactionService,
    ) {}

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
        $financialAccount = $this->multiAccountManagement->findActiveCurrentTenantAccount($request->accountId);

        $idempotencyRecord = $this->tenantIdempotencyService->reserveOptional(
            'tenant_expense.create',
            $request->idempotencyKey,
            $this->tenantExpenseCreateIdempotencyPayload($request)
        );

        if ($idempotencyRecord !== null && $this->tenantIdempotencyService->isReplay($idempotencyRecord)) {
            $this->tenantIdempotencyService->replay($idempotencyRecord);
        }

        $code = $this->tableIdGenerationService->generate('tenant_expenses', CarbonImmutable::now());
        $imageReference = null;

        try {
            if ($request->imageReference !== null) {
                $imageReference = $this->fileStorageUtility->uploadImage(
                    $request->imageReference,
                    $this->imageDirectory($request->tenantId, $code),
                    self::IMAGE_STORAGE_DISK,
                    'expense_reference',
                );
            }

            $expense = DB::transaction(function () use ($request, $code, $imageReference, $financialAccount) {
                $expense = $this->repository->create([
                    'tenant_id' => $request->tenantId,
                    'code' => $code,
                    'account_id' => $financialAccount->id,
                    'description' => $request->description,
                    'amount' => $request->amount,
                    'expense_type_id' => $request->expenseTypeId,
                    'image_reference' => $imageReference,
                    'created_by' => $request->createdBy,
                ]);

                $accountingTransaction = $this->tenantAccountingService->recordExpenseCreation(
                    $expense,
                    $expense->description,
                    (float) $expense->amount,
                    $financialAccount->currency,
                    $expense->created_by
                );
                $this->financialAccountTransactionService->recordExpensePayment(
                    $financialAccount,
                    (float) $expense->amount,
                    $expense->code,
                    TenantExpense::class,
                    $expense->description,
                    $expense->created_by,
                    $accountingTransaction->id,
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
            $this->fileStorageUtility->deleteFile($imageReference, self::IMAGE_STORAGE_DISK);

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
        $financialAccount = $this->multiAccountManagement->findActiveCurrentTenantAccount($request->accountId);
        $data = [];

        if ($request->updateKey !== $expense->update_key) {
            throw new AlreadyUpdatedException('This item is already updated.Please Refresh');
        }

        if ($request->description !== null) {
            $data['description'] = $request->description;
        }

        if ($request->hasExpenseTypeId) {
            $data['expense_type_id'] = $request->expenseTypeId;
        }

        $data['account_id'] = $financialAccount->id;

        $oldImageReference = $expense->image_reference;
        $newImageReference = null;

        if ($request->imageReference !== null) {
            $newImageReference = $this->fileStorageUtility->uploadImage(
                $request->imageReference,
                $this->imageDirectory($expense->tenant_id, $expense->code),
                self::IMAGE_STORAGE_DISK,
                'expense_reference',
            );
            $data['image_reference'] = $newImageReference;
        } elseif ($request->removeImageReference && $oldImageReference !== null) {
            $data['image_reference'] = null;
        }

        if ($data === []) {
            return TenantExpenseDetail::fromModel($expense);
        }
        $data['update_key'] = $expense->update_key + 1;

        $auditFields = array_values(array_diff(array_keys($data), ['image_reference']));
        $original = $expense->only($auditFields);
        $original['has_image_reference'] = filled($oldImageReference);

        try {
            $updatedExpense = DB::transaction(function () use ($expense, $data, $original, $auditFields, $financialAccount) {
                $updatedExpense = $this->repository->updateWithLock($expense, $data);

                $this->tenantAccountingService->syncExpense(
                    $updatedExpense,
                    $updatedExpense->description,
                    (float) $updatedExpense->amount,
                    $financialAccount->currency,
                );

                $after = $updatedExpense->only($auditFields);
                $after['has_image_reference'] = filled($updatedExpense->image_reference);
                $this->tenantAuditLogService->log(
                    'tenant_expense.updated',
                    TenantExpense::class,
                    $updatedExpense->id,
                    [
                        'before' => $original,
                        'after' => $after,
                    ]
                );

                return $updatedExpense;
            });
        } catch (Throwable $exception) {
            $this->fileStorageUtility->deleteFile($newImageReference, self::IMAGE_STORAGE_DISK);

            throw $exception;
        }

        if ($oldImageReference !== $updatedExpense->image_reference) {
            $this->fileStorageUtility->deleteFile($oldImageReference, self::IMAGE_STORAGE_DISK);
        }

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

    public function detail(string $expenseCode): TenantExpenseFullDetail
    {
        $this->permissionService->authorizeExpenseList();
        $expense = ctype_digit($expenseCode)
            ? $this->findExpenseForCurrentTenant((int) $expenseCode)
            : $this->findExpenseForCurrentTenantByCode($expenseCode);
        $expiresAt = null;
        $imageUrl = null;

        if (filled($expense->image_reference)) {
            $expiration = now()->addMinutes(self::IMAGE_URL_TTL_MINUTES);
            $imageUrl = $this->fileStorageUtility->getTemporaryFileUrl(
                $expense->image_reference,
                $expiration,
                self::IMAGE_STORAGE_DISK,
            );
            $expiresAt = $expiration->toISOString();
        }

        return TenantExpenseFullDetail::fromModelWithImage($expense, $imageUrl, $expiresAt);
    }

    public function delete(int $expenseId): void
    {
        $this->permissionService->authorizeExpenseDelete();
        $expense = $this->findExpenseForCurrentTenant($expenseId);

        $imageReference = $expense->image_reference;

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

        $this->fileStorageUtility->deleteFile($imageReference, self::IMAGE_STORAGE_DISK);

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
            'account_id' => $request->accountId,
            'expense_type_id' => $request->expenseTypeId,
            'created_by' => $request->createdBy,
            'image_reference_sha256' => $request->imageReference === null
                ? null
                : hash_file('sha256', $request->imageReference->getRealPath()),
        ];
    }

    protected function imageDirectory(int $tenantId, string $expenseCode): string
    {
        return "tenant-expenses/{$tenantId}/{$expenseCode}/reference";
    }

    protected function resolveCurrentPage(): int
    {
        return max(1, (int) request()->query('page', 1));
    }
}
