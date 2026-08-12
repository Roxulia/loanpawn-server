<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\TenantAccountingCreate;
use App\DataObjects\ResponseObjects\AccountingLedger;
use App\DataObjects\ResponseObjects\TenantAccountingDetail;
use App\DataObjects\ResponseObjects\TenantAccountingListPage;
use App\DataObjects\ResponseObjects\TenantAccountingOverview;
use App\Enums\AccountingCategory;
use App\Exceptions\InvalidTenantRequest;
use App\Exports\TenantAccountingLedgerExport;
use App\Models\CoreModule\Currency;
use App\Models\CoreModule\TenantUser;
use App\Models\PawnModule\PawnInterestPayment;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Models\TenantAccountingTransactions;
use App\Repository\TenantAccountingTransactionRepository;
use App\Services\BaseTenantService;
use App\Support\TenantScopedCacheKeys;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenantAccountingTransactionService extends BaseTenantService
{
    protected const LIST_CACHE_TTL_SECONDS = 600;

    protected const LEDGER_MAX_TIME_RANGE_MONTHS = 3;

    protected const LEDGER_MAX_HISTORY_MONTHS = 6;

    public function __construct(
        private TenantAccountingTransactionRepository $repository,
        private TenantUserPermissionService $permissionService,
        private TenantScopedCacheKeys $tenantScopedCacheKeys,
        private TenantAccountingDayService $accountingDayService,
    ) {}

    public function overview(): TenantAccountingOverview
    {
        $this->permissionService->authorizeAccountingList();
        $version = $this->tenantScopedCacheKeys->currentVersion('tenant-accounting-transaction-overview');

        return Cache::remember(
            $this->tenantScopedCacheKeys->listKey('tenant-accounting-transaction-overview', $version).':month:'.substr($this->accountingDayService->currentBusinessDate(), 0, 7),
            now()->addSeconds(self::LIST_CACHE_TTL_SECONDS),
            function (): TenantAccountingOverview {
                $today = Carbon::parse($this->accountingDayService->currentBusinessDate());
                $monthIncoming = $this->repository->transactionTotalBetween('incoming', $today->copy()->startOfMonth(), $today->copy()->endOfMonth());
                $monthOutgoing = $this->repository->transactionTotalBetween('outgoing', $today->copy()->startOfMonth(), $today->copy()->endOfMonth());
                $largestFlow = max($monthIncoming, $monthOutgoing, 1);

                return new TenantAccountingOverview(
                    liquidCapital: $this->repository->allTimeNetBalance(),
                    monthIncoming: $monthIncoming,
                    monthOutgoing: $monthOutgoing,
                    incomingProgress: round(min(100, ($monthIncoming / $largestFlow) * 100), 1),
                    outgoingProgress: round(min(100, ($monthOutgoing / $largestFlow) * 100), 1),
                );
            },
        );
    }

    public function buildAccountingLedger(Carbon $startDate, Carbon $endDate, int $perPage = 15): AccountingLedger
    {
        $this->permissionService->authorizeAccountingList();
        $this->validateLedgerTimeRange($startDate, $endDate);
        $paginator = $this->repository->paginateAccountingLedger($startDate, $endDate, $perPage);
        $offset = $paginator->firstItem() === null ? 0 : $paginator->firstItem() - 1;
        $openingBalance = $this->repository->balanceBeforeLedgerRow($startDate, $offset);

        return new AccountingLedger(
            entries: $this->repository->mapLedgerEntries($paginator->items(), $openingBalance),
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
        $export = new TenantAccountingLedgerExport(
            entries: $this->repository->mapLedgerEntries(
                $this->repository->getAccountingLedger($startDate, $endDate),
                $openingBalance,
            ),
            startDate: $startDate,
            endDate: $endDate,
            tenantName: $this->getCurrentTenantName(),
            openingBalance: $openingBalance,
        );

        return response()->streamDownload(
            function () use ($export): void {
                echo Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);
            },
            sprintf('general-ledger-%s-to-%s.xlsx', $startDate->toDateString(), $endDate->toDateString()),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function listIncomingTransactions(int $perPage = 15): TenantAccountingListPage
    {
        return $this->rememberList('tenant-accounting-transaction-incoming-list', $perPage, fn () => $this->repository->listIncomingTransactions($this->accountingDayService->currentBusinessDate(), $perPage));
    }

    public function listOutgoingTransactions(int $perPage = 15): TenantAccountingListPage
    {
        return $this->rememberList('tenant-accounting-transaction-outgoing-list', $perPage, fn () => $this->repository->listOutgoingTransactions($this->accountingDayService->currentBusinessDate(), $perPage));
    }

    public function list(int $perPage = 15, ?string $search = null): TenantAccountingListPage
    {
        $this->permissionService->authorizeAccountingList();
        $page = $this->resolveCurrentPage();
        $search = $this->normalizeSearch($search);
        $version = $this->tenantScopedCacheKeys->currentVersion('tenant-accounting-transaction-list');
        $key = $this->tenantScopedCacheKeys->paginatedListKey('tenant-accounting-transaction-list', $version, $page, $perPage);

        if ($search !== null) {
            $key .= ':search:'.sha1(mb_strtolower($search));
        }

        return Cache::remember(
            $key,
            now()->addSeconds(self::LIST_CACHE_TTL_SECONDS),
            fn (): TenantAccountingListPage => TenantAccountingListPage::fromPaginator($this->repository->paginate($perPage, $search)),
        );
    }

    public function create(TenantAccountingCreate $request, AccountingCategory $accountingCategory): TenantAccountingDetail
    {
        $tenantId = $this->resolveCurrentTenantId();
        $accounting = DB::transaction(function () use ($request, $accountingCategory, $tenantId): TenantAccountingTransactions {
            $day = $this->accountingDayService->ensureOpenForTransaction($request->createdBy);

            return $this->repository->create([
                'tenant_id' => $tenantId,
                'accounting_day_id' => $day->id,
                'business_date' => $day->business_date,
                'description' => $request->description,
                'transaction_direction' => $request->transactionType,
                'accounting_category' => $accountingCategory,
                'amount' => $request->amount,
                'occurred_at' => now(),
                'created_by' => $request->createdBy,
                'reference_id' => $request->referenceId,
                'reference_type' => $request->referenceType,
            ]);
        });

        $this->flushListCache($tenantId);

        return TenantAccountingDetail::fromModel($accounting);
    }

    public function recordLoanCreation(PawnLoanContractSlip $loanContractSlip, string $description, float $amount, float $exchangeRate, Currency $currency, TenantUser $createdBy): TenantAccountingTransactions
    {
        $tenantId = $this->resolveCurrentTenantId();
        $accounting = $this->repository->create([
            'tenant_id' => $tenantId,
            'description' => $description,
            'transaction_direction' => 'outgoing',
            'accounting_category' => AccountingCategory::Asset,
            'currency_id' => $currency->getKey(),
            'exchange_rate' => $exchangeRate,
            'amount' => $amount,
            'occurred_at' => now(),
            'created_by' => $createdBy->getKey(),
            'reference_id' => $loanContractSlip->getKey(),
            'reference_type' => $loanContractSlip::class,
        ]);

        $this->flushListCache($tenantId);

        return TenantAccountingDetail::fromModel($accounting);
    }

    public function recordLoanRedemption(PawnLoanContractSlip $loanContractSlip, string $description, float $amount, float $exchangeRate, Currency $currency, TenantUser $createdBy): TenantAccountingTransactions
    {
        $tenantId = $this->resolveCurrentTenantId();
        $accounting = $this->repository->create([
            'tenant_id' => $tenantId,
            'description' => $description,
            'transaction_direction' => 'incoming',
            'accounting_category' => AccountingCategory::Asset,
            'currency_id' => $currency->getKey(),
            'exchange_rate' => $exchangeRate,
            'amount' => $amount,
            'occurred_at' => now(),
            'created_by' => $createdBy->getKey(),
            'reference_id' => $loanContractSlip->getKey(),
            'reference_type' => $loanContractSlip::class,
        ]);

        $this->flushListCache($tenantId);

        return TenantAccountingDetail::fromModel($accounting);
    }

     public function recordInterestPayment(PawnInterestPayment $pawnInterestPayment, string $description, float $amount,float $exchangeRate, Currency $currency, TenantUser $createdBy): TenantAccountingTransactions
    {
        $tenantId = $this->resolveCurrentTenantId();
        $accounting = $this->repository->create([
            'tenant_id' => $tenantId,
            'description' => $description,
            'transaction_direction' => 'incoming',
            'accounting_category' => AccountingCategory::Revenue,
            'currency_id' => $currency->getKey(),
            'exchange_rate' => $exchangeRate,
            'amount' => $amount,
            'occurred_at' => now(),
            'created_by' => $createdBy->getKey(),
            'reference_id' => $pawnInterestPayment->getKey(),
            'reference_type' => $pawnInterestPayment::class,
        ]);

        $this->flushListCache($tenantId);

        return TenantAccountingDetail::fromModel($accounting);
    }

    public function recordDebtPaymentForReference(Model $reference, string $description, float $amount, AccountingCategory $category, ?int $createdBy = null): TenantAccountingTransactions
    {
        return $this->createForReference($reference, $description, 'incoming', $amount, $category, $createdBy);
    }

    public function createIncomingForReference(Model $reference, string $description, float $amount, AccountingCategory $category, ?int $createdBy = null): TenantAccountingTransactions
    {
        return $this->createForReference($reference, $description, 'incoming', $amount, $category, $createdBy);
    }

    public function createOutgoingForReference(Model $reference, string $description, float $amount, AccountingCategory $category, ?int $createdBy = null): TenantAccountingTransactions
    {
        return $this->createForReference($reference, $description, 'outgoing', $amount, $category, $createdBy);
    }

    public function createInternalTransfer(Model $reference, string $description, float $amount, ?int $createdBy = null): TenantAccountingTransactions
    {
        return $this->createForReference($reference, $description, 'internal', $amount, AccountingCategory::Internal, $createdBy);
    }

    public function syncOutgoingForReference(Model $reference, string $description, float $amount, AccountingCategory $category): void
    {
        $this->syncForReference($reference, $description, 'outgoing', $amount, $category);
    }

    public function syncIncomingForReference(Model $reference, string $description, float $amount, AccountingCategory $category): void
    {
        $this->syncForReference($reference, $description, 'incoming', $amount, $category);
    }

    public function deleteForReference(Model $reference): void
    {
        $tenantId = null;

        DB::transaction(function () use ($reference, &$tenantId): void {
            $accountings = $this->repository->findAllByReferenceWithLock($reference);

            if ($accountings->isEmpty()) {
                return;
            }

            foreach ($accountings as $accounting) {
            $this->accountingDayService->assertDayEditable($accounting->accountingDay);
            }

            $tenantId = $accountings->first()->tenant_id;

            foreach ($accountings as $accounting) {
            $this->repository->delete($accounting);
            }
        });

        $this->flushListCache($tenantId);
    }

    private function createForReference(Model $reference, string $description, string $direction, float $amount, AccountingCategory $category, ?int $createdBy): TenantAccountingTransactions
    {
        $accounting = DB::transaction(function () use ($reference, $description, $direction, $amount, $category, $createdBy): TenantAccountingTransactions {
            $day = $this->accountingDayService->ensureOpenForTransaction($createdBy);

            return $this->repository->create([
                'tenant_id' => $this->resolveCurrentTenantId(),
                'accounting_day_id' => $day->id,
                'business_date' => $day->business_date,
                'description' => $description,
                'transaction_direction' => $direction,
                'accounting_category' => $category,
                'amount' => $amount,
                'occurred_at' => now(),
                'created_by' => $createdBy,
                'reference_id' => $reference->getKey(),
                'reference_type' => $reference::class,
            ]);
        });

        $this->flushListCache();

        return $accounting;
    }

    private function syncForReference(Model $reference, string $description, string $direction, float $amount, AccountingCategory $category): void
    {
        DB::transaction(function () use ($reference, $description, $direction, $amount, $category): void {
            $accounting = $this->repository->findByReferenceWithLock($reference);

            if ($accounting === null) {
                $this->createForReference($reference, $description, $direction, $amount, $category, null);

                return;
            }

            $financialChange = $accounting->transaction_direction !== $direction
                || $accounting->accounting_category !== $category
                || abs((float) $accounting->amount - $amount) > 0.00005;

            if ($financialChange) {
            $this->accountingDayService->assertDayEditable($accounting->accountingDay);
            } elseif (! $this->accountingDayService->isDayEditable($accounting->accountingDay)) {
                return;
            }

            $this->repository->update($accounting, [
                'description' => $description,
                'transaction_direction' => $direction,
                'accounting_category' => $category,
                'amount' => $amount,
            ]);
        });

        $this->flushListCache();
    }

    public function assertReferenceFinancialDataEditable(Model $reference): void
    {
        $accountings = $this->repository->findAllByReference($reference);

        foreach ($accountings as $accounting) {
            $this->accountingDayService->assertDayEditable($accounting->accountingDay);
        }
    }

    private function rememberList(string $namespace, int $perPage, callable $paginator): TenantAccountingListPage
    {
        $this->permissionService->authorizeAccountingList();
        $page = $this->resolveCurrentPage();
        $version = $this->tenantScopedCacheKeys->currentVersion($namespace);

        return Cache::remember(
            $this->tenantScopedCacheKeys->paginatedListKey($namespace, $version, $page, $perPage).':date:'.$this->accountingDayService->currentBusinessDate(),
            now()->addSeconds(self::LIST_CACHE_TTL_SECONDS),
            fn (): TenantAccountingListPage => TenantAccountingListPage::fromPaginator($paginator()),
        );
    }

    private function flushListCache(?int $tenantId = null): void
    {
        foreach ([
            'tenant-accounting-transaction-list',
            'tenant-accounting-transaction-incoming-list',
            'tenant-accounting-transaction-outgoing-list',
            'tenant-accounting-transaction-overview',
        ] as $namespace) {
            $this->tenantScopedCacheKeys->bumpVersion($namespace, tenantId: $tenantId);
        }
    }

    private function resolveCurrentPage(): int
    {
        return max(1, (int) request()->query('page', 1));
    }

    private function normalizeSearch(?string $search): ?string
    {
        if ($search === null) {
            return null;
        }

        $search = trim($search);

        return $search === '' ? null : $search;
    }

    private function validateLedgerTimeRange(Carbon $startDate, Carbon $endDate): void
    {
        if ($startDate->greaterThan($endDate)) {
            throw new InvalidTenantRequest('Start date must be before end date.');
        }

        if ($startDate->lessThan(Carbon::now()->subMonths(self::LEDGER_MAX_HISTORY_MONTHS))) {
            throw new InvalidTenantRequest('Start date cannot be more than '.self::LEDGER_MAX_HISTORY_MONTHS.' months in the past.');
        }

        if ($startDate->diffInMonths($endDate) > self::LEDGER_MAX_TIME_RANGE_MONTHS) {
            throw new InvalidTenantRequest('Time range cannot exceed '.self::LEDGER_MAX_TIME_RANGE_MONTHS.' months.');
        }
    }
}
