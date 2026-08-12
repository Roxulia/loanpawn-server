<?php

namespace App\Services\TenantModule\Accounting;

use App\DataObjects\RequestObjects\StoreFinancialAccountRequest;
use App\DataObjects\RequestObjects\UpdateFinancialAccountRequest;
use App\DataObjects\ResponseObjects\DefaultDataListPage;
use App\DataObjects\ResponseObjects\FinancialAccountResource;
use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\TenantAccessDenied;
use App\Models\FinancialAccount;
use App\Repository\Accounting\MultiAccountRepository;
use App\Services\BaseTenantService;
use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use App\Services\TableIdGenerationService;
use App\Utility\MessageCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MultiAccountManagement extends BaseTenantService
{
    public function __construct(
        private MultiAccountRepository $repository,
        private FinancialAccountTransactionService $transactionService,
        private TenantLicenseService $tenantLicenseService,
        private TableIdGenerationService $tableIdGenerationService,
    ) {}

    public function list(int $perPage = 15, ?string $search = null): DefaultDataListPage
    {
        $page = $this->repository->paginate($this->resolveCurrentTenantId(), $perPage, $search);
        $page->through(fn (FinancialAccount $account) => FinancialAccountResource::fromModel($account)->toArray());

        return DefaultDataListPage::fromPaginator($page);
    }

    public function show(string $accountCode): FinancialAccountResource
    {
        return FinancialAccountResource::fromModel($this->findCurrentTenantAccount($accountCode));
    }

    public function findActiveCurrentTenantAccount(?int $accountId = null): FinancialAccount
    {
        $tenantId = $this->resolveCurrentTenantId();
        $account = $accountId === null
            ? $this->repository->activeDefaultAccount($tenantId)
            : $this->repository->findActiveById($tenantId, $accountId);

        if (! $account) {
            throw new TenantAccessDenied('Active financial account not found for the current tenant.');
        }

        if ($account->currency === null || ! $account->currency->is_active) {
            throw new InvalidTenantRequest('The selected financial account must have an active currency.');
        }

        return $account;
    }

    public function findCurrentTenantAccountById(int $accountId): FinancialAccount
    {
        $account = $this->repository->findById($this->resolveCurrentTenantId(), $accountId);
        if (! $account) {
            throw new TenantAccessDenied('Financial account not found for the current tenant.');
        }

        return $account;
    }

    public function create(StoreFinancialAccountRequest $request): FinancialAccountResource
    {
        $tenantId = $this->resolveCurrentTenantId();

        $account = DB::transaction(function () use ($tenantId, $request): FinancialAccount {
            if ($this->tenantLicenseService->checkIfLimitReach('current_account_count', $tenantId, true)) {
                throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceResourceLimitReached));
            }

            $type = $this->repository->findVisibleAccountType($tenantId, trim($request->accountType));
            $currency = $this->repository->findVisibleCurrency($tenantId, trim($request->currencyType));
            if (! $type || ! $currency) {
                throw new InvalidTenantRequest('An active account type and currency available to this tenant are required.');
            }

            $createdBy = $this->currentTenantUserId();
            $account = $this->repository->create([
                'tenant_id' => $tenantId,
                'account_type_id' => $type->id,
                'currency_id' => $currency->id,
                'account_number' => $this->nullableTrim($request->accountNumber),
                'account_name' => trim($request->accountName),
                'account_code' => $this->tableIdGenerationService->generateForTenant($tenantId, 'financial_accounts', CarbonImmutable::now()),
                'balance' => 0,
                'is_active' => true,
                'is_default' => false,
                'is_deleted' => false,
                'allow_negative_balance' => $request->allowNegativeBalance,
                'created_by' => $createdBy,
            ]);

            $this->transactionService->recordOpeningBalance($account, $request->balance, $createdBy);
            $this->tenantLicenseService->incrementAccountCount($tenantId);

            return $account;
        });

        return FinancialAccountResource::fromModel($this->repository->findById($tenantId, $account->id));
    }

    public function update(string $accountCode, UpdateFinancialAccountRequest $request): FinancialAccountResource
    {
        $tenantId = $this->resolveCurrentTenantId();

        $account = DB::transaction(function () use ($tenantId, $accountCode, $request): FinancialAccount {
            $account = $this->lockedAccount($tenantId, $accountCode);
            if ($account->update_key !== $request->updateKey) {
                throw new InvalidTenantRequest('This financial account has already been updated. Refresh and try again.');
            }

            if ($account->is_default && (! $request->isDefault || ! $request->isActive)) {
                throw new InvalidTenantRequest('The default financial account must remain active and default. Select another default account first.');
            }

            if ($request->isDefault) {
                if (! $request->isActive) {
                    throw new InvalidTenantRequest('A default financial account must be active.');
                }
                $this->repository->clearDefault($tenantId, $account->id);
            }

            return $this->repository->update($account, [
                'account_name' => trim($request->name),
                'is_active' => $request->isActive,
                'is_default' => $request->isDefault,
                'account_number' => $this->nullableTrim($request->accountNumber),
                'update_key' => $account->update_key + 1,
            ]);
        });

        return FinancialAccountResource::fromModel($account->load(['accountType', 'currency']));
    }

    public function delete(string $accountCode): void
    {
        $tenantId = $this->resolveCurrentTenantId();

        DB::transaction(function () use ($tenantId, $accountCode): void {
            $account = $this->lockedAccount($tenantId, $accountCode);
            if ($account->is_default) {
                throw new InvalidTenantRequest('The default financial account cannot be deleted. Select another default account first.');
            }

            if (abs((float) $account->balance) >= 0.00005) {
                throw new InvalidTenantRequest('A financial account with a non-zero balance cannot be deleted. Transfer or adjust its balance first.');
            }

            $this->repository->update($account, [
                'is_deleted' => true,
                'is_active' => false,
                'deleted_by' => $this->currentTenantUserId(),
                'update_key' => $account->update_key + 1,
            ]);
            $this->tenantLicenseService->decrementAccountCount($tenantId);
        });
    }

    public function createDefaultForTenant(int $tenantId, ?int $createdBy = null): FinancialAccount
    {
        return DB::transaction(function () use ($tenantId, $createdBy): FinancialAccount {
            $existing = $this->repository->defaultAccount($tenantId);
            if ($existing) {
                if (! $existing->is_active) {
                    return $this->repository->update($existing, [
                        'is_active' => true,
                        'update_key' => $existing->update_key + 1,
                    ]);
                }

                return $existing;
            }

            $oldest = $this->repository->oldestAccount($tenantId);
            if ($oldest) {
                return $this->repository->update($oldest, [
                    'is_default' => true,
                    'is_active' => true,
                    'update_key' => $oldest->update_key + 1,
                ]);
            }

            if ($this->tenantLicenseService->checkIfLimitReach('current_account_count', $tenantId, true)) {
                throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceResourceLimitReached));
            }

            $type = $this->repository->findVisibleAccountType($tenantId, 'cash');
            $currency = $this->repository->findVisibleCurrency($tenantId, 'MMK');
            if (! $type || ! $currency) {
                throw new InvalidTenantRequest('Cash account type and default currency are required to create the tenant account.');
            }

            $account = $this->repository->create([
                'tenant_id' => $tenantId,
                'account_type_id' => $type->id,
                'currency_id' => $currency->id,
                'account_name' => 'Cash Account',
                'account_code' => $this->tableIdGenerationService->generateForTenant($tenantId, 'financial_accounts', CarbonImmutable::now()),
                'balance' => 0,
                'is_active' => true,
                'is_default' => true,
                'is_deleted' => false,
                'allow_negative_balance' => false,
                'created_by' => $createdBy,
            ]);
            $this->tenantLicenseService->incrementAccountCount($tenantId);

            return $account;
        });
    }

    public function ensureDefaults(bool $dryRun = false): array
    {
        $summary = ['tenants_checked' => 0, 'accounts_created' => 0, 'accounts_promoted' => 0];
        foreach ($this->repository->tenantIdsWithoutDefault() as $tenantId) {
            $summary['tenants_checked']++;
            $existing = $this->repository->oldestAccount((int) $tenantId);
            $summary[$existing ? 'accounts_promoted' : 'accounts_created']++;
            if (! $dryRun) {
                $this->createDefaultForTenant((int) $tenantId);
            }
        }

        return $summary;
    }

    private function findCurrentTenantAccount(string $accountCode): FinancialAccount
    {
        $account = $this->repository->findByCode($this->resolveCurrentTenantId(), $accountCode);
        if (! $account) {
            throw new TenantAccessDenied('Financial account not found for the current tenant.');
        }

        return $account;
    }

    private function lockedAccount(int $tenantId, string $accountCode): FinancialAccount
    {
        $account = $this->repository->findByCodeForUpdate($tenantId, $accountCode);
        if (! $account) {
            throw new TenantAccessDenied('Financial account not found for the current tenant.');
        }

        return $account;
    }

    private function currentTenantUserId(): ?int
    {
        return Auth::guard('tenantuser')->id();
    }

    private function nullableTrim(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
