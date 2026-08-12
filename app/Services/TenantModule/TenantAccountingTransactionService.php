<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\TenantAccountingCreate;
use App\DataObjects\RequestObjects\TenantAccountingTransactionRecord;
use App\DataObjects\ResponseObjects\AccountingLedger;
use App\DataObjects\ResponseObjects\TenantAccountingDetail;
use App\DataObjects\ResponseObjects\TenantAccountingListPage;
use App\DataObjects\ResponseObjects\TenantAccountingOverview;
use App\Enums\AccountingCategory;
use App\Exceptions\InvalidTenantRequest;
use App\Exports\TenantAccountingLedgerExport;
use App\Models\CoreModule\Currency;
use App\Models\CoreModule\TenantCapital;
use App\Models\CoreModule\TenantDebt;
use App\Models\CoreModule\TenantExpense;
use App\Models\PawnModule\PawnInterestPayment;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Models\PawnModule\PawnRedemption;
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
        $referenceType = $request->referenceType;
        $reference = null;

        if ($referenceType !== null && $request->referenceId !== null) {
            $reference = new $referenceType;
            $reference->setAttribute($reference->getKeyName(), $request->referenceId);
        }
        $accounting = $this->recordTransaction(new TenantAccountingTransactionRecord(
            reference: $reference,
            description: $request->description,
            transactionDirection: $request->transactionType,
            accountingCategory: $accountingCategory,
            amount: $request->amount,
            createdBy: $request->createdBy,
        ));

        return TenantAccountingDetail::fromModel($accounting);
    }

    public function recordTransaction(TenantAccountingTransactionRecord $request): TenantAccountingTransactions
    {
        $tenantId = $this->resolveCurrentTenantId();
        $accounting = DB::transaction(function () use ($request, $tenantId): TenantAccountingTransactions {
            $day = $this->accountingDayService->ensureOpenForTransaction($request->createdBy);
            $reportingAmount = $request->reportingAmount;

            if ($reportingAmount === null && $request->exchangeRate !== null) {
                $reportingAmount = $request->amount * $request->exchangeRate;
            }

            return $this->repository->create([
                'tenant_id' => $tenantId,
                'accounting_day_id' => $day->id,
                'business_date' => $day->business_date,
                'description' => $request->description,
                'transaction_direction' => $request->transactionDirection,
                'accounting_category' => $request->accountingCategory,
                'amount' => $request->amount,
                'currency_id' => $request->currencyId,
                'reporting_amount' => $reportingAmount,
                'exchange_rate' => $request->exchangeRate,
                'occurred_at' => $request->occurredAt ?? now(),
                'created_by' => $request->createdBy,
                'reference_id' => $request->reference?->getKey(),
                'reference_type' => $request->reference === null ? null : $request->reference::class,
                'legacy_accounting_id' => $request->legacyAccountingId,
                'update_key' => 0,
                'is_deleted' => false,
            ]);
        });

        $this->flushListCache($tenantId);

        return $accounting;
    }

    public function recordLoanCreation(PawnLoanContractSlip $loanContractSlip, string $description, float $amount, Currency $currency, ?int $createdBy = null, ?float $exchangeRate = null): TenantAccountingTransactions
    {
        return $this->recordOperation($loanContractSlip, $description, 'outgoing', AccountingCategory::Asset, $amount, $createdBy, $currency, $exchangeRate);
    }

    public function recordCapitalCreation(TenantCapital $capital, string $description, float $amount, Currency $currency, ?int $createdBy = null): TenantAccountingTransactions
    {
        return $this->recordOperation($capital, $description, 'incoming', AccountingCategory::Equity, $amount, $createdBy, $currency);
    }

    public function recordExpenseCreation(TenantExpense $expense, string $description, float $amount, Currency $currency, ?int $createdBy = null): TenantAccountingTransactions
    {
        return $this->recordOperation($expense, $description, 'outgoing', AccountingCategory::Expense, $amount, $createdBy, $currency);
    }

    public function recordDebtCreation(TenantDebt $debt, string $description, float $amount, bool $isInternal, Currency $currency, ?int $createdBy = null): TenantAccountingTransactions
    {
        return $this->recordOperation($debt, $description, $isInternal ? 'internal' : 'outgoing', $isInternal ? AccountingCategory::Internal : AccountingCategory::Asset, $amount, $createdBy, $currency);
    }

    public function recordLoanRedemption(PawnRedemption $redemption, string $description, float $amount, Currency $currency, ?int $createdBy = null): TenantAccountingTransactions
    {
        return $this->recordOperation($redemption, $description, 'incoming', AccountingCategory::Asset, $amount, $createdBy, $currency);
    }

    public function recordRedemptionChange(PawnRedemption $redemption, string $description, float $amount, Currency $currency, ?int $createdBy = null): TenantAccountingTransactions
    {
        return $this->recordOperation($redemption, $description, 'outgoing', AccountingCategory::Asset, $amount, $createdBy, $currency);
    }

    public function recordInterestPayment(PawnInterestPayment $payment, string $description, float $amount, Currency $currency, ?int $createdBy = null): TenantAccountingTransactions
    {
        return $this->recordOperation($payment, $description, 'incoming', AccountingCategory::Revenue, $amount, $createdBy, $currency);
    }

    public function recordInterestPaymentChange(PawnInterestPayment $payment, string $description, float $amount, Currency $currency, ?int $createdBy = null): TenantAccountingTransactions
    {
        return $this->recordOperation($payment, $description, 'outgoing', AccountingCategory::Asset, $amount, $createdBy, $currency);
    }

    public function recordDebtPaymentForReference(Model $reference, string $description, float $amount, AccountingCategory $category, ?int $createdBy = null): TenantAccountingTransactions
    {
        return $this->recordOperation($reference, $description, 'incoming', $category, $amount, $createdBy);
    }

    public function recordDebtPayment(TenantDebt $debt, string $description, float $amount, Currency $currency, ?int $createdBy = null): TenantAccountingTransactions
    {
        return $this->recordOperation($debt, $description, 'incoming', AccountingCategory::Asset, $amount, $createdBy, $currency);
    }

    public function recordDebtPaymentChange(TenantDebt $debt, string $description, float $amount, Currency $currency, ?int $createdBy = null): TenantAccountingTransactions
    {
        return $this->recordOperation($debt, $description, 'outgoing', AccountingCategory::Asset, $amount, $createdBy, $currency);
    }

    public function createIncomingForReference(Model $reference, string $description, float $amount, AccountingCategory $category, ?int $createdBy = null): TenantAccountingTransactions
    {
        return $this->recordOperation($reference, $description, 'incoming', $category, $amount, $createdBy);
    }

    public function createOutgoingForReference(Model $reference, string $description, float $amount, AccountingCategory $category, ?int $createdBy = null): TenantAccountingTransactions
    {
        return $this->recordOperation($reference, $description, 'outgoing', $category, $amount, $createdBy);
    }

    public function createInternalTransfer(Model $reference, string $description, float $amount, ?int $createdBy = null, ?Currency $currency = null, ?float $exchangeRate = null): TenantAccountingTransactions
    {
        return $this->recordOperation($reference, $description, 'internal', AccountingCategory::Internal, $amount, $createdBy, $currency, $exchangeRate);
    }

    public function recordTransferFee(Model $reference, string $description, float $amount, Currency $currency, ?int $createdBy = null): TenantAccountingTransactions
    {
        return $this->recordOperation($reference, $description, 'outgoing', AccountingCategory::Expense, $amount, $createdBy, $currency);
    }

    public function syncOutgoingForReference(Model $reference, string $description, float $amount, AccountingCategory $category): void
    {
        $this->syncForReference($reference, $description, 'outgoing', $amount, $category);
    }

    public function syncExpense(TenantExpense $expense, string $description, float $amount, Currency $currency): void
    {
        $this->syncForReference($expense, $description, 'outgoing', $amount, AccountingCategory::Expense, $currency);
    }

    public function syncCapital(TenantCapital $capital, string $description, float $amount, Currency $currency): void
    {
        $this->syncForReference($capital, $description, 'incoming', $amount, AccountingCategory::Equity, $currency);
    }

    public function syncDebt(TenantDebt $debt, string $description, float $amount, Currency $currency): void
    {
        $this->syncForReference($debt, $description, 'outgoing', $amount, AccountingCategory::Asset, $currency);
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

    private function recordOperation(Model $reference, string $description, string $direction, AccountingCategory $category, float $amount, ?int $createdBy, ?Currency $currency = null, ?float $exchangeRate = null): TenantAccountingTransactions
    {
        return $this->recordTransaction(new TenantAccountingTransactionRecord(
            reference: $reference,
            description: $description,
            transactionDirection: $direction,
            accountingCategory: $category,
            amount: $amount,
            createdBy: $createdBy,
            currencyId: $currency?->getKey(),
            exchangeRate: $exchangeRate,
        ));
    }

    private function syncForReference(Model $reference, string $description, string $direction, float $amount, AccountingCategory $category, ?Currency $currency = null): void
    {
        DB::transaction(function () use ($reference, $description, $direction, $amount, $category, $currency): void {
            $accounting = $this->repository->findByReferenceWithLock($reference);

            if ($accounting === null) {
                $this->recordOperation($reference, $description, $direction, $category, $amount, null, $currency);

                return;
            }

            $financialChange = $accounting->transaction_direction !== $direction
                || $accounting->accounting_category !== $category
                || abs((float) $accounting->amount - $amount) > 0.00005
                || $accounting->currency_id !== $currency?->getKey();

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
                'currency_id' => $currency?->getKey(),
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
