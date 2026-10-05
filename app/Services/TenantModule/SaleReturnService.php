<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\TenantAccountingTransactionRecord;
use App\Enums\AccountingCategory;
use App\Models\SalesModule\SaleOrder;
use App\Models\SalesModule\SaleReturn;
use App\Models\SalesModule\SaleReturnLine;
use App\Repository\SalesRepository;
use App\Services\BaseTenantService;
use App\Services\TableIdGenerationService;
use App\Services\TenantModule\Accounting\FinancialAccountTransactionService;
use App\Services\TenantModule\Accounting\MultiAccountManagement;
use App\Services\TenantModule\TenantCurrencyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class SaleReturnService extends BaseTenantService
{
    public function __construct(
        private SalesRepository $repository,
        private InventoryService $inventoryService,
        private OwnershipService $ownershipService,
        private SaleReceivableService $receivableService,
        private TenantIdempotencyService $idempotencyService,
        private TableIdGenerationService $codeGenerator,
        private MultiAccountManagement $accountManagement,
        private TenantAccountingTransactionService $accountingService,
        private FinancialAccountTransactionService $accountTransactionService,
        private TenantCurrencyService $currencyService,
    ) {}

    // List the return history for a tenant Sales order.
    public function returns(string $orderCode): array
    {
        $order = $this->orderByCode($orderCode);
        return $this->repository->returnsForOrder((int) $order->tenant_id, (int) $order->id)
            ->map(fn (SaleReturn $return) => $this->resource($return))->all();
    }

    // Return delivered items, restore their lot ownership, credit receivables, and refund any remainder.
    public function create(string $orderCode, array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('sales.return.create', ['sale_code' => $orderCode] + $data, function (int $tenantId) use ($orderCode, $data): array {
            return DB::transaction(function () use ($tenantId, $orderCode, $data): array {
                $order = $this->orderByCode($orderCode, true);
                if (in_array($order->status, ['DRAFT', 'CANCELLED'], true)) {
                    throw ValidationException::withMessages(['sale_code' => ['Only delivered Sales orders can be returned.']]);
                }
                $return = $this->repository->createReturn($tenantId, [
                    'code' => $this->codeGenerator->generateForTenant($tenantId, 'sales_returns', CarbonImmutable::now()),
                    'sales_order_id' => $order->id, 'returned_at' => $data['returned_at'], 'note' => $data['note'] ?? null,
                    'total_amount' => 0, 'receivable_credit_amount' => 0, 'cash_refund_amount' => 0,
                    'created_by' => Auth::guard('tenantuser')->id(),
                ]);
                $totalAmount = 0.0; $creditTotal = 0.0; $refundTotal = 0.0;
                foreach ($data['items'] as $itemData) {
                    $allocation = $this->repository->allocationByCode($tenantId, $itemData['delivery_allocation_code'], true);
                    if ($allocation === null || (int) $allocation->line->sales_order_id !== (int) $order->id) {
                        throw ValidationException::withMessages(['items' => ['The delivered allocation does not belong to this Sales order.']]);
                    }
                    $quantity = (float) $itemData['quantity'];
                    $alreadyReturned = $this->repository->returnedForAllocation($tenantId, (int) $allocation->id);
                    if ($quantity > (float) $allocation->quantity - $alreadyReturned + 0.001) {
                        throw ValidationException::withMessages(['items' => ['Return quantity exceeds the unreturned delivered quantity.']]);
                    }
                    $location = $this->inventoryService->locationForSaleReturn($itemData['location_code']);
                    $disposition = strtoupper($itemData['disposition']);
                    if (($disposition === 'RESTOCK' && !$location->is_sellable) || ($disposition === 'QUARANTINE' && $location->is_sellable)) {
                        throw ValidationException::withMessages(['items' => ['Return location sellability must match the selected disposition.']]);
                    }
                    $unitCodes = $this->returnUnitCodes($allocation, $quantity, $itemData['inventory_unit_codes'] ?? [], $tenantId);
                    $line = $this->repository->createReturnLine($tenantId, [
                        'code' => $this->codeGenerator->generateForTenant($tenantId, 'sales_return_lines', CarbonImmutable::now()),
                        'sales_return_id' => $return->id, 'sales_delivery_allocation_id' => $allocation->id,
                        'inventory_location_id' => $location->id, 'quantity' => $quantity, 'cash_refund_amount' => 0,
                        'receivable_credit_amount' => 0, 'disposition' => $disposition,
                        'inventory_unit_codes' => $unitCodes === [] ? null : json_encode($unitCodes),
                    ]);
                    $movement = $this->inventoryService->receiveSaleReturn([
                        'inventory_item_code' => $allocation->line->inventoryItem->code,
                        'location_code' => $location->code, 'quantity' => $quantity,
                        'inventory_unit_codes' => $unitCodes, 'source_code' => $line->code,
                    ], 'sale-return-inventory-'.$line->code);
                    $this->ownershipService->restoreForSaleReturn(
                        $allocation->line->ownedItem->code, $allocation->lot->code, $quantity, $line->code,
                    );
                    $cost = round($quantity * (float) $allocation->unit_cost, 2);
                    $cogsLedger = $this->post($line, $order, 'incoming', AccountingCategory::Expense, $cost, 'Reverse cost of goods sold for sale return '.$line->code);
                    $assetLedger = $this->post($line, $order, 'incoming', AccountingCategory::Asset, $cost, 'Inventory asset restored for sale return '.$line->code);
                    $writeoffLedgerId = null;
                    if ($disposition === 'QUARANTINE' && $cost > 0) {
                        $this->post($line, $order, 'outgoing', AccountingCategory::Expense, $cost, 'Quarantine write-off for sale return '.$line->code);
                        $writeoff = $this->post($line, $order, 'outgoing', AccountingCategory::Asset, $cost, 'Quarantine inventory asset write-off '.$line->code);
                        $writeoffLedgerId = (int) $writeoff->id;
                    }
                    $this->repository->updateReturnLine($line, [
                        'inventory_movement_id' => $movement['movement_id'],
                        'cogs_reversal_transaction_id' => $cogsLedger->id,
                        'inventory_asset_return_transaction_id' => $assetLedger->id,
                        'inventory_writeoff_transaction_id' => $writeoffLedgerId,
                    ]);
                    $lineAmount = round($quantity * (float) $allocation->unit_price, 2);
                    $credit = $this->receivableService->applyReturnCredit($order, $lineAmount, $line->code);
                    $refund = round($lineAmount - $credit, 2);
                    if ($refund > 0.001) {
                        if (empty($data['financial_account_id'])) throw ValidationException::withMessages(['financial_account_id' => ['Choose the account that issues the cash refund.']]);
                        $this->refundPayments($tenantId, $order, $line, $refund, (int) $data['financial_account_id'], $data['returned_at']);
                    }
                    $this->repository->updateReturnLine($line, ['receivable_credit_amount' => $credit, 'cash_refund_amount' => $refund]);
                    $totalAmount += $lineAmount; $creditTotal += $credit; $refundTotal += $refund;
                }
                $return->update(['total_amount' => round($totalAmount, 2), 'receivable_credit_amount' => round($creditTotal, 2), 'cash_refund_amount' => round($refundTotal, 2)]);
                return $this->resource($this->repository->returnsForOrder($tenantId, (int) $order->id)->firstWhere('id', $return->id));
            });
        }, 201, $idempotencyKey);
    }

    private function orderByCode(string $code, bool $lock = false): SaleOrder
    {
        $order = $this->repository->orderByCode($this->resolveCurrentTenantId(), $code, $lock);
        if ($order === null) abort(404);
        return $order;
    }

    private function returnUnitCodes($allocation, float $quantity, array $requested, int $tenantId): array
    {
        if ($allocation->line->tracking_mode !== 'SERIALIZED') {
            if ($requested !== []) throw ValidationException::withMessages(['items' => ['Unit identifiers only apply to serialized items.']]);
            return [];
        }
        if (abs($quantity - round($quantity)) > 0.001) throw ValidationException::withMessages(['quantity' => ['Serialized return quantities must be whole numbers.']]);
        $issuedCodes = $allocation->inventory_unit_codes ?? [];
        $returnedCodes = $this->repository->returnedUnitCodesForAllocation($tenantId, (int) $allocation->id);
        $available = array_values(array_diff($issuedCodes, $returnedCodes));
        $selected = $requested === [] ? array_slice($available, 0, (int) $quantity) : $requested;
        if (count($selected) !== (int) $quantity || array_diff($selected, $available) !== []) {
            throw ValidationException::withMessages(['inventory_unit_codes' => ['Select unreturned serialized units from this delivery allocation.']]);
        }
        return $selected;
    }

    private function refundPayments(int $tenantId, SaleOrder $order, SaleReturnLine $line, float $amount, int $accountId, string $refundedAt): void
    {
        $account = $this->accountManagement->findActiveCurrentTenantAccount($accountId);
        if (strtoupper((string) $account->currency?->code) !== strtoupper($order->currency_code)) {
            throw ValidationException::withMessages(['financial_account_id' => ['Refund account currency must match the sale.']]);
        }
        $left = round($amount, 2);
        foreach ($this->repository->paymentsForOrder($tenantId, (int) $order->id) as $payment) {
            if ($left <= 0.001) break;
            $alreadyRefunded = (float) $payment->refunds->sum('amount');
            $available = max(0, (float) $payment->amount - $alreadyRefunded);
            $refundAmount = round(min($left, $available), 2);
            if ($refundAmount <= 0) continue;
            $refund = $this->repository->createPaymentRefund($tenantId, (int) $payment->id, [
                'code' => $this->codeGenerator->generateForTenant($tenantId, 'sales_payment_refunds', CarbonImmutable::now()),
                'financial_account_id' => $account->id, 'refunded_at' => $refundedAt, 'amount' => $refundAmount,
                'note' => 'Customer return '.$line->code, 'created_by' => Auth::guard('tenantuser')->id(),
            ]);
            $ledger = $this->post($refund, $order, 'outgoing', AccountingCategory::Asset, $refundAmount, 'Cash refund for sale return '.$line->code);
            $this->repository->updateRefundAccounting($refund, (int) $ledger->id);
            $this->accountTransactionService->recordAdjustment($account, $refundAmount, 'credit', $refund->code,
                $refund::class, 'Customer sale return refund', Auth::guard('tenantuser')->id(), $ledger->id);
            $left = round($left - $refundAmount, 2);
        }
        if ($left > 0.001) throw ValidationException::withMessages(['financial_account_id' => ['Refund amount exceeds payments received for this sale.']]);
    }

    private function post($reference, SaleOrder $order, string $direction, AccountingCategory $category, float $amount, string $description)
    {
        $currency = $this->currencyService->findActiveVisibleByCodeForTenant((int) $order->tenant_id, $order->currency_code);
        return $this->accountingService->recordTransaction(new TenantAccountingTransactionRecord(
            reference: $reference, description: $description, transactionDirection: $direction,
            accountingCategory: $category, amount: $amount, createdBy: Auth::guard('tenantuser')->id(), currencyId: $currency?->id,
        ));
    }

    private function resource(SaleReturn $return): array
    {
        return [
            'code' => $return->code, 'returned_at' => $return->returned_at?->toDateString(), 'note' => $return->note,
            'total_amount' => $return->total_amount, 'receivable_credit_amount' => $return->receivable_credit_amount,
            'cash_refund_amount' => $return->cash_refund_amount,
            'items' => $return->lines->map(fn (SaleReturnLine $line) => [
                'code' => $line->code, 'delivery_allocation_code' => $line->allocation?->code,
                'quantity' => $line->quantity, 'disposition' => $line->disposition,
                'receivable_credit_amount' => $line->receivable_credit_amount, 'cash_refund_amount' => $line->cash_refund_amount,
            ])->all(),
        ];
    }

    private function runIdempotent(string $operation, array $payload, callable $callback, int $status, ?string $key): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        $record = $this->idempotencyService->reserveOptional($operation, $key, $payload);
        if ($record !== null && $this->idempotencyService->isReplay($record)) $this->idempotencyService->replay($record);
        try {
            return DB::transaction(function () use ($callback, $tenantId, $record, $status): array {
                $result = $callback($tenantId);
                if ($record !== null) $this->idempotencyService->markCompleted($record, $status, ['data' => $result]);
                return $result;
            });
        } catch (Throwable $exception) {
            if ($record !== null) $this->idempotencyService->markFailed($record);
            throw $exception;
        }
    }
}