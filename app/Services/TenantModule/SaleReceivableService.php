<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\TenantAccountingTransactionRecord;
use App\Enums\AccountingCategory;
use App\Models\SalesModule\SaleDelivery;
use App\Models\SalesModule\SaleOrder;
use App\Models\SalesModule\SaleReceivable;
use App\Repository\SaleReceivableRepository;
use App\Services\BaseTenantService;
use App\Services\TableIdGenerationService;
use App\Services\TenantModule\Accounting\FinancialAccountTransactionService;
use App\Services\TenantModule\Accounting\MultiAccountManagement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleReceivableService extends BaseTenantService
{
    public function __construct(
        private SaleReceivableRepository $repository,
        private TenantCurrencyService $currencyService,
        private TableIdGenerationService $codeGenerator,
        private TenantAccountingTransactionService $accountingService,
        private MultiAccountManagement $accountManagement,
        private FinancialAccountTransactionService $accountTransactionService,
    ) {}

    // List sale receivables for the shared Tenant Debt screen.
    public function list(): array
    {
        return $this->repository->list($this->resolveCurrentTenantId())->map(fn (SaleReceivable $row) => $this->resource($row))->all();
    }

    // Return an order's receivables for its sale detail resource.
    public function listForOrder(int $orderId): array
    {
        return $this->repository->historyForOrder($this->resolveCurrentTenantId(), $orderId)
            ->map(fn (SaleReceivable $row) => $this->resource($row))->all();
    }

    // Resolve one tenant sale receivable for its detail screen.
    public function detail(string $code): array
    {
        $row = $this->repository->findByCode($this->resolveCurrentTenantId(), $code);
        abort_if($row === null, 404);
        return $this->resource($row);
    }

    // Create the unpaid portion of one delivery and keep future interest disabled.
    public function createForDelivery(SaleOrder $order, SaleDelivery $delivery, float $amount): ?SaleReceivable
    {
        if ($amount <= 0.001) return null;
        return DB::transaction(function () use ($order, $delivery, $amount): SaleReceivable {
            $tenantId = $this->resolveCurrentTenantId();
            $existing = $this->repository->findByDelivery($tenantId, (int) $delivery->id, true);
            if ($existing !== null) return $existing;
            $currency = $this->currencyService->findActiveVisibleByCodeForTenant($tenantId, $order->currency_code);
            $receivable = $this->repository->create($tenantId, [
                'code' => $this->codeGenerator->generateForTenant($tenantId, 'sale_receivables', CarbonImmutable::now()),
                'customer_id' => $order->customer_id, 'sales_order_id' => $order->id, 'sales_delivery_id' => $delivery->id,
                'currency_id' => $currency->id, 'original_amount' => round($amount, 2), 'balance_amount' => round($amount, 2),
                'apply_interest' => false, 'interest_rate' => null, 'interest_type_id' => null,
                'status' => 'OPEN', 'created_by' => Auth::guard('tenantuser')->id(),
            ]);
            return $receivable;
        });
    }

    // Expose order totals used to cap sale payments and allocate collections.
    public function balanceForOrder(int $orderId): float { return $this->repository->balanceForOrder($this->resolveCurrentTenantId(), $orderId); }
    public function paidForOrder(int $orderId): float { return $this->repository->paidForOrder($this->resolveCurrentTenantId(), $orderId); }
    public function originalForOrder(int $orderId): float { return $this->repository->originalForOrder($this->resolveCurrentTenantId(), $orderId); }

    // Apply incoming sale payment to the oldest open delivery receivables.
    public function collectForOrder(SaleOrder $order, float $amount, array $data, int $accountId): float
    {
        $tenantId = $this->resolveCurrentTenantId();
        return $this->collectForOrderReceivables($tenantId, $order, $amount, $data, $accountId);
    }

    // Apply a tender against one selected receivable and return any balance excess as change.
    public function collect(string $code, float $amount, array $data, int $accountId): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        DB::transaction(function () use ($tenantId, $code, $amount, $data, $accountId): void {
            $receivable = $this->repository->findByCode($tenantId, $code, true);
            abort_if($receivable === null, 404);
            if ((float) $receivable->balance_amount <= 0.001) {
                throw ValidationException::withMessages(['amount' => ['This sale receivable has no remaining balance.']]);
            }
            $applied = min(round($amount, 2), (float) $receivable->balance_amount);
            $data['change_amount'] = round($amount - $applied, 2);
            $this->collectForOrderReceivables($tenantId, $receivable->order, $applied, $data, $accountId, $code);
        });
        return $this->detail($code);
    }

    // Apply incoming amounts to locked receivables in delivery order.
    private function collectForOrderReceivables(int $tenantId, SaleOrder $order, float $amount, array $data, int $accountId, ?string $receivableCode = null): float
    {
        return DB::transaction(function () use ($tenantId, $order, $amount, $data, $accountId, $receivableCode): float {
            $account = $this->accountManagement->findActiveCurrentTenantAccount($accountId);
            if (strtoupper((string) $account->currency?->code) !== strtoupper($order->currency_code)) {
                throw ValidationException::withMessages(['financial_account_id' => [__('sales.account_currency_mismatch')]]);
            }
            $left = round($amount, 2);
            foreach ($this->repository->forOrder($tenantId, (int) $order->id, true, $receivableCode) as $receivable) {
                if ($left <= 0.001) break;
                $paid = round(min($left, (float) $receivable->balance_amount), 2);
                if ($paid <= 0) continue;
                $change = ($left - $paid) <= 0.001 ? round((float) ($data['change_amount'] ?? 0), 2) : 0.0;
                $payment = $this->repository->createPayment($tenantId, [
                    'code' => $this->codeGenerator->generateForTenant($tenantId, 'sale_receivable_payments', CarbonImmutable::now()),
                    'sale_receivable_id' => $receivable->id, 'paid_at' => $data['paid_at'], 'amount' => $paid,
                    'payment_amount' => round($paid + $change, 2), 'change_amount' => $change,
                    'financial_account_id' => $account->id, 'reference' => $data['reference'] ?? null,
                    'note' => $data['note'] ?? null, 'created_by' => Auth::guard('tenantuser')->id(),
                ]);
                $ledger = $this->accountingService->recordTransaction(new TenantAccountingTransactionRecord(
                    reference: $payment, description: 'Collection of sale receivable '.$receivable->code,
                    transactionDirection: 'incoming', accountingCategory: AccountingCategory::Revenue,
                    amount: $paid, createdBy: Auth::guard('tenantuser')->id(), currencyId: $receivable->currency_id,
                ));
                $this->repository->updatePaymentAccounting($payment, (int) $ledger->id);
                $this->accountTransactionService->recordAdjustment($account, (float) $payment->payment_amount, 'debit', $payment->code,
                    $payment::class, 'Sale receivable payment received', Auth::guard('tenantuser')->id(), $ledger->id);
                if ($change > 0.001) {
                    $changeLedger = $this->accountingService->recordTransaction(new TenantAccountingTransactionRecord(
                        reference: $payment, description: 'Change returned for sale receivable '.$receivable->code,
                        transactionDirection: 'outgoing', accountingCategory: AccountingCategory::Asset,
                        amount: $change, createdBy: Auth::guard('tenantuser')->id(), currencyId: $receivable->currency_id,
                    ));
                    $this->accountTransactionService->recordAdjustment($account, $change, 'credit', $payment->code,
                        $payment::class, 'Sale receivable payment change', Auth::guard('tenantuser')->id(), $changeLedger->id);
                }
                $this->repository->updateBalance($receivable, (float) $receivable->balance_amount - $paid);
                $left = round($left - $paid, 2);
            }
            return round($amount - $left, 2);
        });
    }

    // Build the shared-list row without exposing the internal model.
    private function resource(SaleReceivable $row): array
    {
        return [
            'record_type' => 'SALE_RECEIVABLE', 'id' => (int) $row->id, 'code' => $row->code,
            'customer_id' => $row->customer_id, 'customer_code' => $row->customer?->code,
            'customer_name' => $row->customer?->name, 'source_code' => $row->order?->code,
            'delivery_code' => $row->delivery?->code, 'currency_code' => $row->currency?->code,
            'amount' => (string) $row->original_amount, 'original_amount' => (string) $row->original_amount,
            'principal_balance' => (string) $row->balance_amount, 'balance_amount' => (string) $row->balance_amount,
            'apply_interest' => false, 'interest_rate' => null, 'interest_type_id' => null,
            'description' => 'Sale receivable '.$row->order?->code, 'tag' => 'SALE_RECEIVABLE',
            'is_paid' => (float) $row->balance_amount <= 0.001, 'status' => $row->status,
            'created_at' => $row->created_at?->toISOString(),
            'payments' => $row->payments->map(fn ($payment) => [
                'code' => $payment->code, 'payment_amount' => (string) $payment->payment_amount,
                'amount_applied' => (string) $payment->amount, 'change_amount' => (string) $payment->change_amount,
                'principal_paid' => (string) $payment->amount, 'interest_paid' => '0.00',
                'payment_at' => $payment->paid_at?->toISOString(),
            ])->all(),
        ];
    }
}
