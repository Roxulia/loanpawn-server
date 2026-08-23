<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\TenantAccountingCreate;
use App\DataObjects\ResponseObjects\AccountingLedger;
use App\DataObjects\ResponseObjects\TenantAccountingDetail;
use App\DataObjects\ResponseObjects\TenantAccountingListPage;
use App\DataObjects\ResponseObjects\TenantAccountingOverview;
use App\Exports\TenantAccountingLedgerExport;
use App\Exceptions\InvalidTenantRequest;
use App\Models\CoreModule\TenantAccounting;
use App\Repository\TenantAccountingRepository;
use App\Services\BaseTenantService;
use App\Support\TenantScopedCacheKeys;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenantAccountingService extends BaseTenantService
{
    protected const TENANT_ACCOUNTING_LIST_CACHE_TTL_SECONDS = 600;
    protected const TENANT_ACCOUNTING_LEDGER_CACHE_TTL_SECONDS = 600;
    protected const TENANT_ACCOUNTING_LEDGER_MAX_TIME_RANGE_MONTHS = 3;
    protected const TENANT_ACCOUNTING_LEDGER_MAX_HISTORY_MONTHS = 3;

    public function __construct(
        private TenantAccountingRepository $repository,
        private TenantUserPermissionService $permissionService,
        private TenantScopedCacheKeys $tenantScopedCacheKeys,
    ) {
    }

    public function overview(): TenantAccountingOverview
    {
        $this->permissionService->authorizeAccountingList();
        $version = $this->tenantScopedCacheKeys->currentVersion('tenant-accounting-overview');

        return Cache::remember(
            $this->tenantScopedCacheKeys->listKey('tenant-accounting-overview', $version),
            now()->addSeconds(self::TENANT_ACCOUNTING_LIST_CACHE_TTL_SECONDS),
            function () {
                $today = Carbon::today();
                $monthStart = $today->copy()->startOfMonth();
                $monthEnd = $today->copy()->endOfMonth();
                $monthIncoming = $this->repository->transactionTotalBetween('incoming', $monthStart, $monthEnd);
                $monthOutgoing = $this->repository->transactionTotalBetween('outgoing', $monthStart, $monthEnd);
                $largestFlow = max($monthIncoming, $monthOutgoing, 1);

                return new TenantAccountingOverview(
                    liquidCapital: $this->repository->allTimeNetBalance(),
                    monthIncoming: $monthIncoming,
                    monthOutgoing: $monthOutgoing,
                    incomingProgress: round(min(100, ($monthIncoming / $largestFlow) * 100), 1),
                    outgoingProgress: round(min(100, ($monthOutgoing / $largestFlow) * 100), 1),
                );
            }
        );
    }

    public function buildAccountingLedger(Carbon $startDate, Carbon $endDate, int $perPage = 15): AccountingLedger
    {
        $this->permissionService->authorizeAccountingList();
        $this->validateLedgerTimeRange($startDate, $endDate);
        $paginator = $this->repository->paginateAccountingLedger($startDate, $endDate, $perPage);
        $openingBalance = $this->repository->balanceBeforeLedgerRow($startDate, $paginator->firstItem() === null ? 0 : $paginator->firstItem() - 1);
        $ledgerRows = $this->repository->mapLedgerEntries($paginator->items(), $openingBalance);

        return new AccountingLedger(
             entries: $ledgerRows,
             startDate: $startDate,
             endDate: $endDate,
             tenantName: $this->getCurrentTenantName(),
             openingBalance: $openingBalance,
             currentPage: $paginator->currentPage(),
             lastPage: $paginator->lastPage(),
             perPage: $paginator->perPage(),
             total: $paginator->total(),
         );
    }

    public function buildAccoutingLedger(Carbon $startDate, Carbon $endDate, int $perPage = 15): AccountingLedger
    {
        return $this->buildAccountingLedger($startDate, $endDate, $perPage);
    }

    public function downloadAccountingLedger(Carbon $startDate, Carbon $endDate): StreamedResponse
    {
        $this->permissionService->authorizeAccountingList();
        $this->validateLedgerTimeRange($startDate, $endDate);

        $openingBalance = $this->repository->balanceBefore($startDate);
        $entries = $this->repository->mapLedgerEntries(
            $this->repository->getAccountingLedger($startDate, $endDate),
            $openingBalance,
        );
        $export = new TenantAccountingLedgerExport(
            entries: $entries,
            startDate: $startDate,
            endDate: $endDate,
            tenantName: $this->getCurrentTenantName(),
            openingBalance: $openingBalance,
        );
        $fileName = sprintf('general-ledger-%s-to-%s.xlsx', $startDate->toDateString(), $endDate->toDateString());

        return response()->streamDownload(
            function () use ($export): void {
                echo Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);
            },
            $fileName,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function listIncomingTransactions(int $perPage = 15): TenantAccountingListPage
    {
        $this->permissionService->authorizeAccountingList();
        $page = $this->resolveCurrentPage();
        $version = $this->tenantScopedCacheKeys->currentVersion('tenant-accounting-incoming-list');

        return Cache::remember(
            $this->tenantScopedCacheKeys->paginatedListKey('tenant-accounting-incoming-list', $version, $page, $perPage),
            now()->addSeconds(self::TENANT_ACCOUNTING_LIST_CACHE_TTL_SECONDS),
            fn () => TenantAccountingListPage::fromPaginator($this->repository->listIncomingTransactions($perPage))
        );
    }

    public function listOutgoingTransactions(int $perPage = 15): TenantAccountingListPage
    {
        $this->permissionService->authorizeAccountingList();
        $page = $this->resolveCurrentPage();
        $version = $this->tenantScopedCacheKeys->currentVersion('tenant-accounting-outgoing-list');

        return Cache::remember(
            $this->tenantScopedCacheKeys->paginatedListKey('tenant-accounting-outgoing-list', $version, $page, $perPage),
            now()->addSeconds(self::TENANT_ACCOUNTING_LIST_CACHE_TTL_SECONDS),
            fn () => TenantAccountingListPage::fromPaginator($this->repository->listOutgoingTransactions($perPage))
        );
    }

    public function list(int $perPage = 15, ?string $search = null): TenantAccountingListPage
    {
        $this->permissionService->authorizeAccountingList();
        $page = $this->resolveCurrentPage();
        $search = $this->normalizeSearch($search);
        $version = $this->tenantScopedCacheKeys->currentVersion('tenant-accounting-list');

        return Cache::remember(
            $this->tenantAccountingListCacheKey($version, $page, $perPage, $search),
            now()->addSeconds(self::TENANT_ACCOUNTING_LIST_CACHE_TTL_SECONDS),
            fn () => TenantAccountingListPage::fromPaginator($this->repository->paginate($perPage, $search))
        );
    }

    public function create(TenantAccountingCreate $request): TenantAccountingDetail
    {
        $tenantId = $request->tenantId ?? $this->resolveCurrentTenantId();

        $accounting = $this->repository->create([
            'tenant_id' => $tenantId,
            'description' => $request->description,
            'transaction_type' => $request->transactionType,
            'amount' => $request->amount,
            'created_by' => $request->createdBy,
            'reference_id' => $request->referenceId,
            'reference_type' => $request->referenceType,
        ]);

        $this->flushTenantAccountingListCache($tenantId);

        return TenantAccountingDetail::fromModel($accounting);
    }

    public function recordDebtPaymentForReference(Model $reference, string $description, float $amount, ?int $createdBy = null): TenantAccounting
    {
        $accounting = $this->repository->create([
            'tenant_id' => $this->resolveCurrentTenantId(),
            'description' => $description,
            'transaction_type' => 'incoming',
            'amount' => $amount,
            'created_by' => $createdBy,
            'reference_id' => $reference->getKey(),
            'reference_type' => $reference::class,
        ]);

        $this->flushTenantAccountingListCache();

        return $accounting;
    }

    public function createIncomingForReference(Model $reference, string $description, float $amount, ?int $createdBy = null): TenantAccounting
    {
        $accounting = $this->repository->create([
            'tenant_id' => $this->resolveCurrentTenantId(),
            'description' => $description,
            'transaction_type' => 'incoming',
            'amount' => $amount,
            'created_by' => $createdBy,
            'reference_id' => $reference->getKey(),
            'reference_type' => $reference::class,
        ]);

        $this->flushTenantAccountingListCache();

        return $accounting;
    }

    public function createOutgoingForReference(Model $reference, string $description, float $amount, ?int $createdBy = null): TenantAccounting
    {
        $accounting = $this->repository->create([
            'tenant_id' => $this->resolveCurrentTenantId(),
            'description' => $description,
            'transaction_type' => 'outgoing',
            'amount' => $amount,
            'created_by' => $createdBy,
            'reference_id' => $reference->getKey(),
            'reference_type' => $reference::class,
        ]);

        $this->flushTenantAccountingListCache();

        return $accounting;
    }

    public function createInternalTransfer(Model $reference, string $description, float $amount, ?int $createdBy = null): TenantAccounting
    {
        $accounting = $this->repository->create([
            'tenant_id' => $this->resolveCurrentTenantId(),
            'description' => $description,
            'transaction_type' => 'internal',
            'amount' => $amount,
            'created_by' => $createdBy,
            'reference_id' => $reference->getKey(),
            'reference_type' => $reference::class,
        ]);

        $this->flushTenantAccountingListCache();

        return $accounting;
    }

    public function syncOutgoingForReference(Model $reference, string $description, float $amount): void
    {
        DB::transaction(function () use ($reference, $description, $amount): void {
            $accounting = $this->repository->findByReferenceWithLock($reference);

            if ($accounting === null) {
                $this->createOutgoingForReference($reference, $description, $amount);

                return;
            }

            $this->repository->update($accounting, [
                'description' => $description,
                'transaction_type' => 'outgoing',
                'amount' => $amount,
            ]);
        });

        $this->flushTenantAccountingListCache();
    }

    public function syncIncomingForReference(Model $reference, string $description, float $amount): void
    {
        DB::transaction(function () use ($reference, $description, $amount): void {
            $accounting = $this->repository->findByReferenceWithLock($reference);

            if ($accounting === null) {
                $this->createIncomingForReference($reference, $description, $amount);

                return;
            }

            $this->repository->update($accounting, [
                'description' => $description,
                'transaction_type' => 'incoming',
                'amount' => $amount,
            ]);
        });

        $this->flushTenantAccountingListCache();
    }

    public function deleteForReference(Model $reference): void
    {
        $tenantId = null;

        DB::transaction(function () use ($reference, &$tenantId): void {
            $accounting = $this->repository->findByReferenceWithLock($reference);

            if ($accounting === null) {
                return;
            }

            $tenantId = $accounting->tenant_id;
            $this->repository->delete($accounting);
        });

        $this->flushTenantAccountingListCache($tenantId);
    }

    protected function flushTenantAccountingListCache(?int $tenantId = null): void
    {
        $this->tenantScopedCacheKeys->bumpVersion('tenant-accounting-list', tenantId: $tenantId);
        $this->tenantScopedCacheKeys->bumpVersion('tenant-accounting-incoming-list', tenantId: $tenantId);
        $this->tenantScopedCacheKeys->bumpVersion('tenant-accounting-outgoing-list', tenantId: $tenantId);
        $this->tenantScopedCacheKeys->bumpVersion('tenant-accounting-overview', tenantId: $tenantId);
    }

    protected function resolveCurrentPage(): int
    {
        return max(1, (int) request()->query('page', 1));
    }

    protected function validateLedgerTimeRange(Carbon $startDate, Carbon $endDate): void
    {
        if ($startDate->greaterThan($endDate)) {
            throw new InvalidTenantRequest('Start date must be before end date.');
        }

        if ($startDate->lessThan(Carbon::now()->subMonths(self::TENANT_ACCOUNTING_LEDGER_MAX_HISTORY_MONTHS))) {
            throw new InvalidTenantRequest('Start date cannot be more than ' . self::TENANT_ACCOUNTING_LEDGER_MAX_HISTORY_MONTHS . ' months in the past.');
        }

        if ($startDate->diffInMonths($endDate) > self::TENANT_ACCOUNTING_LEDGER_MAX_TIME_RANGE_MONTHS) {
            throw new InvalidTenantRequest('Time range cannot exceed ' . self::TENANT_ACCOUNTING_LEDGER_MAX_TIME_RANGE_MONTHS . ' months.');
        }
    }

    protected function normalizeSearch(?string $search): ?string
    {
        if ($search === null) {
            return null;
        }

        $search = trim($search);

        return $search === '' ? null : $search;
    }

    protected function tenantAccountingListCacheKey(int $version, int $page, int $perPage, ?string $search): string
    {
        $key = $this->tenantScopedCacheKeys->paginatedListKey('tenant-accounting-list', $version, $page, $perPage);

        if ($search === null) {
            return $key;
        }

        return $key . ':search:' . sha1(mb_strtolower($search));
    }
}
