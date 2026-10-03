<?php
namespace App\Services\TenantModule;
use App\DataObjects\RequestObjects\TenantAccountingTransactionRecord;
use App\Enums\AccountingCategory;
use App\Models\PurchasingModule\PurchaseReturnLine;
use App\Models\PurchasingModule\SupplierPayable;
use App\Repository\SupplierPayableRepository;
use App\Services\BaseTenantService;
use App\Services\TableIdGenerationService;
use App\Services\TenantModule\Accounting\FinancialAccountTransactionService;
use App\Services\TenantModule\Accounting\MultiAccountManagement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class SupplierPayableService extends BaseTenantService
{
    public function __construct(private SupplierPayableRepository $repository, private TenantCurrencyService $currencyService, private TableIdGenerationService $codeGenerator, private TenantAccountingTransactionService $accountingService, private MultiAccountManagement $accountManagement, private FinancialAccountTransactionService $accountTransactionService, private TenantIdempotencyService $idempotencyService) {}

    // List Purchasing payables for one order or the unified obligations registry.
    public function list(?string $orderCode = null): array
    {
        return $this->repository->list($this->resolveCurrentTenantId(), $orderCode)->map(fn (SupplierPayable $payable) => $this->resource($payable))->all();
    }

    // Create the received unpaid amount and apply earlier supplier credits in FIFO order.
    public function createForReceipt(int $supplierId, int $receiptId, string $receiptCode, string $currencyCode, float $amount): array
    {
        if ($amount <= 0) return ['payable_code' => null, 'credit_applied_amount' => 0.0];
        $tenantId = $this->resolveCurrentTenantId();
        $currency = $this->currencyService->findActiveVisibleByCodeForTenant($tenantId, $currencyCode);
        $this->repository->lockSupplier($tenantId, $supplierId);
        $payable = $this->repository->forReceipt($tenantId, $receiptId, true);
        if ($payable === null) {
            $payable = $this->repository->createPayable(['tenant_id' => $tenantId, 'code' => $this->codeGenerator->generateForTenant($tenantId, 'supplier_payables', CarbonImmutable::now()), 'supplier_id' => $supplierId, 'purchase_receipt_id' => $receiptId, 'currency_id' => $currency->id, 'original_amount' => $amount, 'balance_amount' => $amount, 'status' => 'OPEN', 'created_by' => Auth::guard('tenantuser')->id()]);
            $this->post($payable, 'incoming', AccountingCategory::Liability, $amount, $currency->id, 'Supplier payable for receipt '.$receiptCode);
        }
        $creditApplied = 0.0;
        foreach ($this->repository->availableCredits($tenantId, $supplierId, (int) $currency->id, true) as $credit) {
            $allocationAmount = round(min((float) $payable->balance_amount, (float) $credit->remaining_amount), 2);
            if ($allocationAmount <= 0) break;
            $allocation = $this->repository->createCreditAllocation(['tenant_id' => $tenantId, 'code' => $this->codeGenerator->generateForTenant($tenantId, 'supplier_credit_allocations', CarbonImmutable::now()), 'supplier_credit_id' => $credit->id, 'supplier_payable_id' => $payable->id, 'amount' => $allocationAmount, 'created_by' => Auth::guard('tenantuser')->id()]);
            $liability = $this->post($allocation, 'outgoing', AccountingCategory::Liability, $allocationAmount, $currency->id, 'Supplier credit applied to payable '.$payable->code);
            $asset = $this->post($allocation, 'outgoing', AccountingCategory::Asset, $allocationAmount, $currency->id, 'Supplier credit applied to purchase '.$receiptCode);
            $this->repository->updateCreditAllocation($allocation, ['accounting_transaction_id' => $liability->id, 'asset_accounting_transaction_id' => $asset->id]);
            $this->repository->updateCreditBalance($credit, (float) $credit->remaining_amount - $allocationAmount);
            $payable = $this->repository->updateBalance($payable, (float) $payable->balance_amount - $allocationAmount);
            $creditApplied += $allocationAmount;
        }
        return ['payable_code' => $payable->code, 'credit_applied_amount' => round($creditApplied, 2)];
    }

    // Reduce a receipt payable by return credit; preserve any excess as supplier credit.
    public function applyReturnCredit(string $receiptCode, float $amount, PurchaseReturnLine $returnItem): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        if ($amount <= 0) return ['adjusted_amount' => 0.0, 'credit_amount' => 0.0, 'credit_code' => null];
        return DB::transaction(function () use ($tenantId, $receiptCode, $amount, $returnItem): array {
            $receiptLine = $returnItem->receiptLine()->with('receipt.order')->firstOrFail();
            $receipt = $receiptLine->receipt;
            $currency = $this->currencyService->findActiveVisibleByCodeForTenant($tenantId, $receipt->order->currency_code);
            $payable = $this->repository->forReceipt($tenantId, $receipt->id, true);
            $adjusted = min($amount, (float) ($payable?->balance_amount ?? 0));
            if ($adjusted > 0 && $payable !== null) {
                $adjustment = $this->repository->createAdjustment(['tenant_id' => $tenantId, 'code' => $this->codeGenerator->generateForTenant($tenantId, 'supplier_payable_adjustments', CarbonImmutable::now()), 'supplier_payable_id' => $payable->id, 'adjustment_type' => 'RETURN_CREDIT', 'amount' => $adjusted, 'source_type' => PurchaseReturnLine::class, 'source_code' => $returnItem->code, 'created_by' => Auth::guard('tenantuser')->id()]);
                $ledger = $this->post($adjustment, 'outgoing', AccountingCategory::Liability, $adjusted, $currency->id, 'Supplier return credit applied to payable '.$payable->code);
                $this->repository->updateAdjustment($adjustment, ['accounting_transaction_id' => $ledger->id]);
                $this->repository->updateBalance($payable, (float) $payable->balance_amount - $adjusted);
            }
            $excess = round(max(0, $amount - $adjusted), 2);
            $creditCode = null;
            if ($excess > 0) {
                $credit = $this->repository->createCredit(['tenant_id' => $tenantId, 'code' => $this->codeGenerator->generateForTenant($tenantId, 'supplier_credits', CarbonImmutable::now()), 'supplier_id' => $receipt->order->supplier_id, 'currency_id' => $currency->id, 'source_return_item_code' => $returnItem->code, 'original_amount' => $excess, 'remaining_amount' => $excess, 'created_by' => Auth::guard('tenantuser')->id()]);
                $this->post($credit, 'incoming', AccountingCategory::Asset, $excess, $currency->id, 'Supplier credit from return '.$receiptCode);
                $creditCode = $credit->code;
            }
            return ['adjusted_amount' => round($adjusted, 2), 'credit_amount' => $excess, 'credit_code' => $creditCode];
        });
    }

    // Settle a payable once per client operation and record both accounting and account movements.
    public function pay(string $payableCode, array $data, ?string $idempotencyKey = null): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        $record = $this->idempotencyService->reserveOptional('purchasing.supplier_payable.payment', $idempotencyKey, ['payable_code' => $payableCode] + $data);
        if ($record !== null && $this->idempotencyService->isReplay($record)) $this->idempotencyService->replay($record);
        try {
            return DB::transaction(function () use ($tenantId, $payableCode, $data, $record): array {
                $payable = $this->repository->findByCode($tenantId, $payableCode, true);
                abort_if($payable === null, 404);
                $account = $this->accountManagement->findActiveCurrentTenantAccount((int) $data['financial_account_id']);
                if ((int) $account->currency_id !== (int) $payable->currency_id) throw ValidationException::withMessages(['financial_account_id' => [__('purchasing.payment_currency_mismatch')]]);
                if ((float) $data['amount'] > (float) $payable->balance_amount + 0.001) throw ValidationException::withMessages(['amount' => [__('purchasing.payment_exceeds_balance')]]);
                $payment = $this->repository->createPayment(['tenant_id' => $tenantId, 'code' => $this->codeGenerator->generateForTenant($tenantId, 'supplier_payable_payments', CarbonImmutable::now()), 'supplier_payable_id' => $payable->id, 'paid_at' => $data['paid_at'], 'amount' => $data['amount'], 'financial_account_id' => $account->id, 'reference' => $data['reference'] ?? null, 'note' => $data['note'] ?? null, 'created_by' => Auth::guard('tenantuser')->id()]);
                $ledger = $this->post($payment, 'outgoing', AccountingCategory::Liability, (float) $payment->amount, (int) $payable->currency_id, 'Supplier payable payment '. $payable->code);
                $this->repository->updatePaymentAccounting($payment, $account->id, $ledger->id);
                $this->accountTransactionService->recordAdjustment($account, (float) $payment->amount, 'credit', $payment->code, $payment::class, 'Supplier payable payment', Auth::guard('tenantuser')->id(), $ledger->id);
                $this->repository->updateBalance($payable, (float) $payable->balance_amount - (float) $payment->amount);
                $result = ['code' => $payment->code, 'paid_at' => $payment->paid_at?->toDateString(), 'amount' => (string) $payment->amount, 'financial_account_id' => $account->id, 'reference' => $payment->reference, 'note' => $payment->note];
                if ($record !== null) $this->idempotencyService->markCompleted($record, 201, ['data' => $result]);
                return $result;
            });
        } catch (Throwable $exception) {
            if ($record !== null) $this->idempotencyService->markFailed($record);
            throw $exception;
        }
    }
    // Shape Purchasing payables for the API without exposing Eloquent models.
    private function resource(SupplierPayable $payable): array
    {
        $payable->loadMissing('payments');
        return ['code' => $payable->code, 'kind' => 'SUPPLIER_PAYABLE', 'supplier_code' => $payable->supplier?->code, 'supplier_name' => $payable->supplier?->person?->name, 'purchase_order_code' => $payable->receipt?->order?->code, 'purchase_receipt_code' => $payable->receipt?->code, 'currency_code' => $payable->currency?->code, 'original_amount' => (string) $payable->original_amount, 'balance_amount' => (string) $payable->balance_amount, 'status' => $payable->status, 'payments' => $payable->payments->map(fn ($payment) => ['code' => $payment->code, 'paid_at' => $payment->paid_at?->toDateString(), 'amount' => (string) $payment->amount])->all()];
    }

    // Record all payable and supplier-credit postings through tenant Accounting.
    private function post($reference, string $direction, AccountingCategory $category, float $amount, int $currencyId, string $description)
    {
        return $this->accountingService->recordTransaction(new TenantAccountingTransactionRecord(reference: $reference, description: $description, transactionDirection: $direction, accountingCategory: $category, amount: $amount, createdBy: Auth::guard('tenantuser')->id(), currencyId: $currencyId));
    }
}
