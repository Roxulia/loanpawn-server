<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\OwnershipAcquisitionCreate;
use App\DataObjects\RequestObjects\TenantAccountingTransactionRecord;
use App\Enums\AccountingCategory;
use App\Models\PurchasingModule\PurchaseOrder;
use App\Models\PurchasingModule\PurchasePayment;
use App\Models\PurchasingModule\PurchaseRefund;
use App\Models\PurchasingModule\PurchaseReceiptLine;
use App\Models\PurchasingModule\PurchaseReturnLine;
use App\Repository\PurchasingRepository;
use App\Services\BaseTenantService;
use App\Services\TableIdGenerationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use App\Services\TenantModule\Accounting\FinancialAccountTransactionService;
use App\Services\TenantModule\Accounting\MultiAccountManagement;
use Illuminate\Validation\ValidationException;
use Throwable;

class PurchasingService extends BaseTenantService
{
    public function __construct(
        private PurchasingRepository $repository,
        private CatalogItemService $catalogItemService,
        private TenantCurrencyService $currencyService,
        private TenantIdempotencyService $idempotencyService,
        private TableIdGenerationService $codeGenerator,
        private PurchaseSupplierService $supplierService,
        private InventoryService $inventoryService,
        private OwnershipService $ownershipService,
        private MultiAccountManagement $accountManagement,
        private TenantAccountingTransactionService $accountingService,
        private FinancialAccountTransactionService $accountTransactionService,
        private SupplierPayableService $supplierPayableService,
    ) {}

    public function orders(?string $search = null): array
    {
        return $this->repository->orders($this->resolveCurrentTenantId(), $search)
            ->map(fn (PurchaseOrder $order) => $this->orderResource($order))->all();
    }

    public function order(string $code): array
    {
        $order = $this->repository->orderByCode($this->resolveCurrentTenantId(), $code);
        abort_if($order === null, 404);
        return $this->orderResource($order, true);
    }

    /** Complete a one-step purchase while keeping every side effect inside one transaction. */
    public function quickPurchase(array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('purchasing.quick.create', $data, function () use ($data): array {
            $supplierCode = $data['supplier_code'] ?? null;
            if ($supplierCode === null) {
                $supplier = $this->supplierService->create($data['new_supplier'], null);
                $supplierCode = $supplier['code'];
            }

            $order = $this->createOrder([
                'supplier_code' => $supplierCode,
                'currency_code' => $data['currency_code'],
                'note' => $data['note'] ?? null,
                'items' => $data['items'],
            ], null);

            $this->transition($order['code'], 'order', null);
            $this->transition($order['code'], 'confirm', null);

            $payment = null;
            $tenderAmount = round((float) $data['tender_amount'], 2);
            if ($tenderAmount > 0) {
                $payment = $this->recordPayment($order['code'], [
                    'paid_at' => $data['purchase_date'],
                    'amount' => $tenderAmount,
                    'financial_account_id' => $data['financial_account_id'],
                    'reference' => $data['payment_reference'] ?? null,
                    'note' => $data['payment_note'] ?? null,
                ], null);
            }

            $receivedItems = array_map(static function (array $item, array $orderItem): array {
                return [
                    'purchase_order_item_code' => $orderItem['code'],
                    'quantity' => (float) $item['ordered_quantity'],
                    'unit_identifiers' => $item['unit_identifiers'] ?? [],
                ];
            }, $data['items'], $order['items']);

            $receipt = $this->recordReceipt($order['code'], [
                'received_at' => $data['purchase_date'],
                'location_code' => $data['location_code'],
                'note' => $data['note'] ?? null,
                'items' => $receivedItems,
            ], null);

            return [
                'order' => $this->order($order['code']),
                'payment' => $payment,
                'receipt' => $receipt,
            ];
        }, 201, $idempotencyKey);
    }

    public function createOrder(array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('purchasing.order.create', $data, function (int $tenantId) use ($data): array {
            $supplier = $this->repository->supplierByCode($tenantId, $data['supplier_code']);
            if ($supplier === null || !$supplier->is_active) {
                throw ValidationException::withMessages(['supplier_code' => [__('purchasing.supplier_unavailable')]]);
            }
            $currencyCode = strtoupper($data['currency_code']);
            $this->currencyService->findActiveVisibleByCodeForTenant($tenantId, $currencyCode);
            $order = $this->repository->createOrder($tenantId, [
                'code' => $this->newCode($tenantId, 'purchase_orders'), 'supplier_id' => $supplier->id,
                'status' => 'DRAFT', 'currency_code' => $currencyCode,
                'note' => $data['note'] ?? null, 'created_by' => Auth::id(),
            ]);
            foreach ($data['items'] as $line) {
                $catalog = null;
                if (!empty($line['catalog_item_code'])) {
                    $catalog = $this->catalogItemService->availableForInventoryByCode($line['catalog_item_code']);
                    if ($catalog === null) {
                        throw ValidationException::withMessages(['items' => [__('purchasing.catalog_item_unavailable')]]);
                    }
                }
                $trackingMode = $catalog['tracking_mode'] ?? $line['tracking_mode'];
                $this->validateTrackedQuantity($trackingMode, (float) $line['ordered_quantity']);
                $this->repository->createOrderLine($tenantId, $order->id, [
                    'code' => $this->newCode($tenantId, 'purchase_order_lines'),
                    'catalog_item_id' => $catalog['id'] ?? null,
                    'item_description' => trim($line['item_description'] ?? $catalog['name']),
                    'tracking_mode' => $trackingMode,
                    'unit_label' => $line['unit_label'] ?? ($catalog['unit']['name'] ?? __('purchasing.default_unit')),
                    'unit_code' => $line['unit_code'] ?? ($catalog['unit_code'] ?? 'unit'),
                    'ordered_quantity' => $line['ordered_quantity'], 'unit_price' => $line['unit_price'],
                ]);
            }
            return $this->orderResource($this->repository->orderByCode($tenantId, $order->code), true);
        }, 201, $idempotencyKey);
    }

    public function updateOrder(string $code, array $data): array
    {
        $tenantId = $this->resolveCurrentTenantId();

        return DB::transaction(function () use ($tenantId, $code, $data): array {
            // Lock the order and allow edits only before its workflow has started.
            $order = $this->repository->orderByCode($tenantId, $code, true);
            abort_if($order === null, 404);
            if ($order->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => [__('purchasing.invalid_order_transition')]]);
            }

            // Validate the replacement supplier and currency before replacing draft lines.
            $supplier = $this->repository->supplierByCode($tenantId, $data['supplier_code']);
            if ($supplier === null || ! $supplier->is_active) {
                throw ValidationException::withMessages(['supplier_code' => [__('purchasing.supplier_unavailable')]]);
            }
            $currencyCode = strtoupper($data['currency_code']);
            $this->currencyService->findActiveVisibleByCodeForTenant($tenantId, $currencyCode);

            $this->repository->updateOrder($order, [
                'supplier_id' => $supplier->id,
                'currency_code' => $currencyCode,
                'note' => $data['note'] ?? null,
            ]);
            $this->repository->deleteOrderLines($tenantId, (int) $order->id);

            foreach ($data['items'] as $line) {
                $catalog = null;
                if (! empty($line['catalog_item_code'])) {
                    $catalog = $this->catalogItemService->availableForInventoryByCode($line['catalog_item_code']);
                    if ($catalog === null) {
                        throw ValidationException::withMessages(['items' => [__('purchasing.catalog_item_unavailable')]]);
                    }
                }

                $trackingMode = $catalog['tracking_mode'] ?? $line['tracking_mode'];
                $this->validateTrackedQuantity($trackingMode, (float) $line['ordered_quantity']);
                $this->repository->createOrderLine($tenantId, (int) $order->id, [
                    'code' => $this->newCode($tenantId, 'purchase_order_lines'),
                    'catalog_item_id' => $catalog['id'] ?? null,
                    'item_description' => trim($line['item_description'] ?? $catalog['name']),
                    'tracking_mode' => $trackingMode,
                    'unit_label' => $line['unit_label'] ?? ($catalog['unit']['name'] ?? __('purchasing.default_unit')),
                    'unit_code' => $line['unit_code'] ?? ($catalog['unit_code'] ?? 'unit'),
                    'ordered_quantity' => $line['ordered_quantity'],
                    'unit_price' => $line['unit_price'],
                ]);
            }

            return $this->orderResource($this->repository->orderByCode($tenantId, $code), true);
        });
    }
    public function transition(string $code, string $action, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('purchasing.order.'.$action, ['order_code' => $code], function (int $tenantId) use ($code, $action): array {
            $order = $this->repository->orderByCode($tenantId, $code, true);
            abort_if($order === null, 404);
            $next = match ([$action, $order->status]) {
                ['order', 'DRAFT'] => 'ORDERED',
                ['confirm', 'ORDERED'] => 'CONFIRMED',
                ['cancel', 'DRAFT'], ['cancel', 'ORDERED'] => 'CANCELLED',
                default => null,
            };
            if ($next === null) {
                throw ValidationException::withMessages(['status' => [__('purchasing.invalid_order_transition')]]);
            }
            if ($action === 'cancel' && $this->repository->orderReceived($tenantId, $order->id) > 0) {
                throw ValidationException::withMessages(['status' => [__('purchasing.cannot_cancel_received')]]);
            }
            $this->repository->updateOrder($order, ['status' => $next, 'ordered_at' => $next === 'ORDERED' ? now()->toDateString() : $order->ordered_at]);
            return $this->orderResource($this->repository->orderByCode($tenantId, $code), true);
        }, 200, $idempotencyKey);
    }

    public function recordPayment(string $orderCode, array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('purchasing.payment.create', ['order_code' => $orderCode] + $data, function (int $tenantId) use ($orderCode, $data): array {
            $order = $this->repository->orderByCode($tenantId, $orderCode, true);
            abort_if($order === null, 404);
            if (in_array($order->status, ['DRAFT', 'CANCELLED'], true)) {
                throw ValidationException::withMessages(['order_code' => [__('purchasing.order_not_payable')]]);
            }
            // Treat Purchasing payments only as pre-receipt supplier advances; later installments use Business Loan payments.
            if ($this->repository->orderReceived($tenantId, $order->id) > 0) {
                throw ValidationException::withMessages(['order_code' => [__('purchasing.use_supplier_loan_for_repayment')]]);
            }
            // Validate the supplier advance account and order currency before recording any payment.
            $account = $this->accountManagement->findActiveCurrentTenantAccount(isset($data['financial_account_id']) ? (int) $data['financial_account_id'] : null);
            if (strtoupper((string) $account->currency?->code) !== strtoupper($order->currency_code)) {
                throw ValidationException::withMessages(['financial_account_id' => [__('purchasing.payment_currency_mismatch')]]);
            }
            $orderTotal = $this->orderTotal($order);
            $payments = $this->repository->paymentsForOrder($tenantId, $order->id);
            $netPaid = (float) $payments->sum(fn ($payment) => (float) $payment->amount - (float) $payment->refunds->sum('amount'));
            if ($netPaid + (float) $data['amount'] > $orderTotal + 0.001) {
                throw ValidationException::withMessages(['amount' => [__('purchasing.payment_exceeds_balance')]]);
            }
            $payment = $this->repository->createPayment($tenantId, [
                'code' => $this->newCode($tenantId, 'purchase_payments'), 'purchase_order_id' => $order->id,
                'paid_at' => $data['paid_at'], 'amount' => $data['amount'], 'financial_account_id' => $account->id,
                'reference' => $data['reference'] ?? null, 'note' => $data['note'] ?? null, 'created_by' => Auth::id(),
            ]);
            // Record the payment as an asset advance and post the matching cash outflow.
            $ledger = $this->recordFinancialPosting($payment, $account->currency, 'outgoing', AccountingCategory::Asset, (float) $payment->amount, 'Supplier advance for purchase '.$order->code);
            $this->repository->updatePaymentAccounting($payment, $account->id, $ledger->id);
            $this->accountTransactionService->recordAdjustment($account, (float) $payment->amount, 'credit', $payment->code, PurchasePayment::class, 'Supplier advance', Auth::id(), $ledger->id);
            return $this->paymentResource($payment->load('refunds'));
        }, 201, $idempotencyKey);
    }
    public function recordRefund(string $paymentCode, array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('purchasing.payment.refund', ['payment_code' => $paymentCode] + $data, function (int $tenantId) use ($paymentCode, $data): array {
            $payment = $this->repository->paymentByCode($tenantId, $paymentCode, true);
            if ($payment === null) {
                throw ValidationException::withMessages(['payment_code' => [__('purchasing.payment_not_found')]]);
            }
            $refunded = (float) $payment->refunds->sum('amount');
            if ($this->repository->orderReceived($tenantId, $payment->order->id) > 0) {
                throw ValidationException::withMessages(['payment_code' => [__('purchasing.use_supplier_loan_for_repayment')]]);
            }
            if ($refunded + (float) $data['amount'] > (float) $payment->amount + 0.001) {
                throw ValidationException::withMessages(['amount' => [__('purchasing.refund_exceeds_payment')]]);
            }
            // Return supplier refunds to the original payment account.
            if ($payment->financial_account_id === null) {
                throw ValidationException::withMessages(['payment_code' => [__('purchasing.refund_account_missing')]]);
            }
            $account = $this->accountManagement->findActiveCurrentTenantAccount((int) $payment->financial_account_id);
            $refund = $this->repository->createRefund($tenantId, $payment->id, [
                'code' => $this->newCode($tenantId, 'purchase_refunds'), 'refunded_at' => $data['refunded_at'],
                'amount' => $data['amount'], 'financial_account_id' => $account->id,
                'reference' => $data['reference'] ?? null, 'note' => $data['note'] ?? null, 'created_by' => Auth::id(),
            ]);
            // Record the refund as an asset inflow and post the matching cash increase.
            $ledger = $this->recordFinancialPosting($refund, $account->currency, 'incoming', AccountingCategory::Asset, (float) $refund->amount, 'Supplier refund for purchase payment '.$payment->code);
            $this->repository->updateRefundAccounting($refund, $account->id, $ledger->id);
            $this->accountTransactionService->recordAdjustment($account, (float) $refund->amount, 'debit', $refund->code, PurchaseRefund::class, 'Supplier refund', Auth::id(), $ledger->id);
            return $this->paymentResource($this->repository->paymentByCode($tenantId, $paymentCode));
        }, 201, $idempotencyKey);
    }
    public function recordReceipt(string $orderCode, array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('purchasing.receipt.create', ['order_code' => $orderCode] + $data, function (int $tenantId) use ($orderCode, $data): array {
            $order = $this->repository->orderByCode($tenantId, $orderCode, true);
            abort_if($order === null, 404);
            if ($order->status !== 'CONFIRMED') {
                throw ValidationException::withMessages(['order_code' => [__('purchasing.order_not_receivable')]]);
            }
            $this->repository->lockSupplier($tenantId, (int) $order->supplier_id);

            $purchaseOrderItems = [];
            foreach ($data['items'] as $itemData) {
                $purchaseOrderItem = $this->repository->lineByCodeForOrder($tenantId, $order->id, $itemData['purchase_order_item_code'], true);
                if ($purchaseOrderItem === null) {
                    throw ValidationException::withMessages(['items' => [__('purchasing.order_line_not_found')]]);
                }
                $this->validateTrackedQuantity($purchaseOrderItem->tracking_mode, (float) $itemData['quantity']);
                if ($this->repository->receivedForOrderLine($tenantId, $purchaseOrderItem->id) + (float) $itemData['quantity'] > (float) $purchaseOrderItem->ordered_quantity + 0.001) {
                    throw ValidationException::withMessages(['items' => [__('purchasing.receipt_exceeds_ordered')]]);
                }
                $purchaseOrderItem->loadMissing(['catalogItem', 'inventoryItem']);
                $purchaseOrderItems[] = [$purchaseOrderItem, (float) $itemData['quantity'], $itemData];
            }
            $receipt = $this->repository->createReceipt($tenantId, [
                'code' => $this->newCode($tenantId, 'purchase_receipts'), 'purchase_order_id' => $order->id,
                'received_at' => $data['received_at'], 'note' => $data['note'] ?? null, 'created_by' => Auth::id(),
            ]);
            $receiptItems = [];
            $receiptValue = 0.0;
            foreach ($purchaseOrderItems as [$purchaseOrderItem, $quantity, $itemData]) {
                // Create the receipt item first so every resulting movement and lot has a stable source reference.
                $receiptItem = $this->repository->createReceiptLine($tenantId, $receipt->id, $purchaseOrderItem->id, $quantity,
                    $this->newCode($tenantId, 'purchase_receipt_lines'), ['inventory_location_code' => $data['location_code']]);
                $inventoryData = [
                    'location_code' => $data['location_code'], 'quantity' => $quantity,
                    'tracking_mode' => $purchaseOrderItem->tracking_mode, 'unit_code' => $purchaseOrderItem->unit_code ?? 'unit',
                    'name' => $purchaseOrderItem->item_description, 'description' => $purchaseOrderItem->item_description,
                    'catalog_item_code' => $purchaseOrderItem->catalogItem?->business_code,
                    'unit_identifiers' => $itemData['unit_identifiers'] ?? [],
                    'source_type' => 'PurchaseReceiptLine', 'source_code' => $receiptItem->code,
                ];
                // Reuse a stable Inventory identity for tracked quantity and serialized goods.
                if ($purchaseOrderItem->tracking_mode !== 'UNIQUE' && $purchaseOrderItem->inventoryItem !== null) {
                    $inventoryData['inventory_item_code'] = $purchaseOrderItem->inventoryItem->code;
                }
                $inventoryItemResource = $this->inventoryService->receive($inventoryData, 'purchasing-receipt-'.$receiptItem->code);
                $inventoryItem = $this->inventoryService->findForOwnershipByCode($inventoryItemResource['code']);
                if ($purchaseOrderItem->tracking_mode !== 'UNIQUE' && $purchaseOrderItem->inventory_item_id === null) {
                    $purchaseOrderItem = $this->repository->updateOrderLine($purchaseOrderItem, ['inventory_item_id' => $inventoryItem->id]);
                }
                $inventoryUnitCodes = array_column($inventoryItemResource['units'] ?? [], 'code');
                // Create a receipt-sourced acquisition lot at the purchase order item's snapshotted unit cost.
                $ownership = $this->ownershipService->acquireFromSource(new OwnershipAcquisitionCreate(
                    inventoryItemCode: $inventoryItemResource['code'], quantity: $quantity,
                    acquiredAt: $data['received_at'], unitCostBasis: (float) $purchaseOrderItem->unit_price,
                    estimatedUnitValue: (float) $purchaseOrderItem->unit_price, currencyCode: $order->currency_code,
                    description: $purchaseOrderItem->item_description,
                ), 'PURCHASING', 'PurchaseReceiptLine', $receiptItem->code, 'purchasing-ownership-'.$receiptItem->code);
                $receivedValue = round($quantity * (float) $purchaseOrderItem->unit_price, 2);
                $receiptValue += $receivedValue;
                $receiptItem = $this->repository->updateReceiptLine($receiptItem, [
                    'inventory_item_id' => $inventoryItem->id, 'inventory_unit_codes' => $inventoryUnitCodes,
                    'owned_item_code' => $ownership['code'], 'acquisition_lot_code' => $ownership['lots'][0]['code'] ?? null,
                    'received_value' => $receivedValue,
                ]);
                // Recognize received goods as an asset in the purchase currency, not as an expense.
                $this->recordFinancialPosting($receiptItem, $this->currencyService->findActiveVisibleByCodeForTenant($tenantId, $order->currency_code), 'internal', AccountingCategory::Asset, $receivedValue, 'Inventory received for purchase '.$order->code);
                $receiptItems[] = $receiptItem;
            }
            // Allocate existing pre-receipt supplier advances against earlier receipt value first.
            $netPayments = (float) $this->repository->paymentsForOrder($tenantId, $order->id)->sum(fn ($payment) => (float) $payment->amount - (float) $payment->refunds->sum('amount'));
            $priorReceiptValue = (float) $this->repository->receiptsForOrder($tenantId, $order->id)->where('id', '!=', $receipt->id)->sum(fn ($priorReceipt) => $priorReceipt->lines->sum('received_value'));
            $advanceForReceipt = min($receiptValue, max(0, $netPayments - $priorReceiptValue));
                        // Create a Purchasing-owned payable after applying advances; pre-existing supplier credits are allocated by the payable service.
            $unpaidReceiptValue = round(max(0, $receiptValue - $advanceForReceipt), 2);
            $payable = $this->supplierPayableService->createForReceipt((int) $order->supplier_id, (int) $receipt->id, (int) $order->id, $receipt->code, $order->currency_code, $unpaidReceiptValue);
            $receipt = $this->repository->updateReceipt($receipt, ['supplier_payable_code' => $payable['payable_code'], 'supplier_credit_applied_amount' => $payable['credit_applied_amount']]);
            return $this->receiptResource($receipt, collect($receiptItems));
        }, 201, $idempotencyKey);
    }
    public function recordReturn(string $orderCode, array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('purchasing.return.create', ['order_code' => $orderCode] + $data, function (int $tenantId) use ($orderCode, $data): array {
            $order = $this->repository->orderByCode($tenantId, $orderCode, true);
            abort_if($order === null, 404);
            $this->repository->lockSupplier($tenantId, (int) $order->supplier_id);
            $receiptItems = [];
            $receiptValue = 0.0;
            foreach ($data['items'] as $itemData) {
                $receiptItem = $this->repository->receiptLineByCode($tenantId, $itemData['purchase_receipt_item_code'], true);
                if ($receiptItem === null || $receiptItem->orderLine->purchase_order_id !== $order->id) {
                    throw ValidationException::withMessages(['items' => [__('purchasing.receipt_line_not_found')]]);
                }
                $this->validateTrackedQuantity($receiptItem->orderLine->tracking_mode, (float) $itemData['quantity']);
                if ($this->repository->returnedForReceiptLine($tenantId, $receiptItem->id) + (float) $itemData['quantity'] > (float) $receiptItem->received_quantity + 0.001) {
                    throw ValidationException::withMessages(['items' => [__('purchasing.return_exceeds_received')]]);
                }
                if ($receiptItem->orderLine->tracking_mode === 'SERIALIZED') {
                    $unitCodes = $itemData['inventory_unit_codes'] ?? [];
                    $alreadyReturnedCodes = $this->repository->returnedUnitCodes($tenantId, $receiptItem->id);
                    if (count($unitCodes) !== (int) $itemData['quantity'] || count(array_unique($unitCodes)) !== count($unitCodes)
                        || array_diff($unitCodes, $receiptItem->inventory_unit_codes ?? []) !== []
                        || array_intersect($unitCodes, $alreadyReturnedCodes) !== []) {
                        throw ValidationException::withMessages(['items' => [__('purchasing.serialized_return_units_required')]]);
                    }
                }
                $receiptItems[] = [$receiptItem, (float) $itemData['quantity'], $itemData];
            }
            $returnRecord = $this->repository->createReturn($tenantId, [
                'code' => $this->newCode($tenantId, 'purchase_returns'), 'purchase_order_id' => $order->id,
                'returned_at' => $data['returned_at'], 'reason' => $data['reason'] ?? null,
                'note' => $data['note'] ?? null, 'created_by' => Auth::id(),
            ]);
            $returnItems = [];
            foreach ($receiptItems as [$receiptItem, $quantity, $itemData]) {
                // Create the return item first so every stock and ownership reduction has a stable source reference.
                $returnItem = $this->repository->createReturnLine($tenantId, $returnRecord->id, $receiptItem->id, $quantity,
                    $this->newCode($tenantId, 'purchase_return_lines'), $itemData['inventory_unit_codes'] ?? []);
                $purchaseOrderItem = $receiptItem->orderLine;
                // Issue only the goods being returned from the selected current custody location.
                $this->inventoryService->issue([
                    'inventory_item_code' => $receiptItem->inventoryItem->code,
                    'location_code' => $itemData['location_code'], 'quantity' => $quantity,
                    'inventory_unit_codes' => $itemData['inventory_unit_codes'] ?? [],
                    'source_type' => 'PurchaseReturnLine', 'source_code' => $returnItem->code,
                ], 'purchasing-return-'.$returnItem->code);
                // Reduce the precise receipt acquisition lot instead of a different lot for the same Catalog item.
                $this->ownershipService->reduceForPurchaseReturn(
                    (string) $receiptItem->owned_item_code, (string) $receiptItem->acquisition_lot_code,
                    $quantity, $returnItem->code, 'purchasing-return-ownership-'.$returnItem->code,
                );
                $returnedValue = round($quantity * (float) $purchaseOrderItem->unit_price, 2);
                // Split the supplier settlement between cash refund and non-cash credit to the linked loan.
                $cashRefundAmount = round((float) ($itemData['cash_refund_amount'] ?? 0), 2);
                if ($cashRefundAmount < 0 || $cashRefundAmount > $returnedValue + 0.001) {
                    throw ValidationException::withMessages(['cash_refund_amount' => [__('purchasing.refund_exceeds_return_value')]]);
                }
                $refundAccount = null;
                $refundLedger = null;
                if ($cashRefundAmount > 0) {
                    $refundAccount = $this->accountManagement->findActiveCurrentTenantAccount(isset($itemData['financial_account_id']) ? (int) $itemData['financial_account_id'] : null);
                    if (strtoupper((string) $refundAccount->currency?->code) !== strtoupper($order->currency_code)) {
                        throw ValidationException::withMessages(['financial_account_id' => [__('purchasing.payment_currency_mismatch')]]);
                    }
                    $refundLedger = $this->recordFinancialPosting($returnItem, $refundAccount->currency, 'incoming', AccountingCategory::Asset, $cashRefundAmount, 'Cash refund for supplier return '.$returnRecord->code);
                    $this->accountTransactionService->recordAdjustment($refundAccount, $cashRefundAmount, 'debit', $returnItem->code, PurchaseReturnLine::class, 'Supplier return cash refund', Auth::id(), $refundLedger->id);
                }
                $supplierCreditAmount = round($returnedValue - $cashRefundAmount, 2);
                // Reduce the receipt payable and retain any excess return value as supplier credit.
                $creditResult = $this->supplierPayableService->applyReturnCredit($receiptItem->receipt->code, $supplierCreditAmount, $returnItem);
                $returnItem = $this->repository->updateReturnLine($returnItem, [
                    'supplier_credit_amount' => $creditResult['credit_amount'], 'supplier_credit_code' => $creditResult['credit_code'],
                    'supplier_payable_adjusted_amount' => $creditResult['adjusted_amount'], 'cash_refund_amount' => $cashRefundAmount,
                    'financial_account_id' => $refundAccount?->id, 'accounting_transaction_id' => $refundLedger?->id,
                ]);
                // Reverse the returned inventory asset without recording a cash movement for supplier credit.
                $this->recordFinancialPosting($returnItem, $this->currencyService->findActiveVisibleByCodeForTenant($tenantId, $order->currency_code), 'internal', AccountingCategory::Asset, $returnedValue, 'Inventory returned for purchase '.$order->code);
                $returnItems[] = $returnItem;
            }
            return $this->returnResource($returnRecord, collect($returnItems));
        }, 201, $idempotencyKey);
    }
    public function payments(string $orderCode): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        $order = $this->repository->orderByCode($tenantId, $orderCode);
        abort_if($order === null, 404);
        return $this->repository->paymentsForOrder($tenantId, $order->id)->map(fn ($payment) => $this->paymentResource($payment))->all();
    }

    public function receipts(string $orderCode): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        $order = $this->repository->orderByCode($tenantId, $orderCode);
        abort_if($order === null, 404);
        return $this->repository->receiptsForOrder($tenantId, $order->id)->map(fn ($receipt) => $this->receiptResource($receipt, $receipt->lines))->all();
    }

    public function returns(string $orderCode): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        $order = $this->repository->orderByCode($tenantId, $orderCode);
        abort_if($order === null, 404);
        return $this->repository->returnsForOrder($tenantId, $order->id)->map(fn ($return) => $this->returnResource($return, $return->lines))->all();
    }

    private function orderResource(PurchaseOrder $order, bool $details = false): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        $lines = $order->lines;
        $ordered = (float) $lines->sum(fn ($line) => (float) $line->ordered_quantity);
        $received = $this->repository->orderReceived($tenantId, $order->id);
        $returned = $this->repository->orderReturned($tenantId, $order->id);
        $amount = $this->orderTotal($order);
        $payments = $this->repository->paymentsForOrder($tenantId, $order->id);
        $paid = (float) $payments->sum('amount');
        $refunded = (float) $payments->sum(fn ($payment) => $payment->refunds->sum('amount'));
        // Payables represent received value that was not already covered by advances.
        // Count the settled portion to keep purchase payment status in sync with payable settlement.
        $payableSettled = (float) $order->supplierPayables->sum(fn ($payable) => max(
            0,
            (float) $payable->original_amount - (float) $payable->balance_amount,
        ));
        $netPaid = max(0, $paid - $refunded) + $payableSettled;
        $allLinesReceived = $lines->isNotEmpty() && $lines->every(fn ($line) =>
            $this->repository->receivedForOrderLine($tenantId, $line->id) + 0.001 >= (float) $line->ordered_quantity);
        $status = $order->status === 'CONFIRMED' && $allLinesReceived ? 'COMPLETED' : $order->status;
        $resource = [
            'code' => $order->code, 'supplier' => $this->supplierService->resource($order->supplier), 'status' => $status,
            'currency_code' => $order->currency_code, 'ordered_at' => $order->ordered_at?->toDateString(), 'note' => $order->note,
            'totals' => ['item_count' => $lines->count(), 'fully_received_item_count' => $lines->filter(fn ($line) =>
                    $this->repository->receivedForOrderLine($tenantId, $line->id) + 0.001 >= (float) $line->ordered_quantity)->count(),
                'amount' => $this->decimal($amount, 2), 'paid_amount' => $this->decimal($paid + $payableSettled, 2),
                'refunded_amount' => $this->decimal($refunded, 2), 'net_paid_amount' => $this->decimal($netPaid, 2)],
            'payment_status' => $amount <= 0 || $netPaid + 0.001 >= $amount ? 'PAID' : ($netPaid <= 0 ? 'UNPAID' : 'PARTIAL'),
            'receipt_status' => $received <= 0 ? 'NOT_RECEIVED' : ($allLinesReceived ? 'RECEIVED' : 'PARTIAL'),
            'return_status' => $returned <= 0 ? 'NOT_RETURNED' : ($returned + 0.001 >= $received ? 'RETURNED' : 'PARTIAL'),
            'created_at' => $order->created_at?->toIso8601String(),
        ];
        if ($details) {
            $resource['items'] = $lines->map(function ($line) use ($tenantId): array {
                $receivedQuantity = $this->repository->receivedForOrderLine($tenantId, $line->id);
                return ['code' => $line->code, 'catalog_item_code' => $line->catalogItem?->business_code,
                    'item_description' => $line->item_description, 'tracking_mode' => $line->tracking_mode, 'unit_label' => $line->unit_label,
                    'ordered_quantity' => $line->ordered_quantity, 'received_quantity' => $this->decimal($receivedQuantity, 3),
                    'incoming_quantity' => $this->decimal(max(0, (float) $line->ordered_quantity - $receivedQuantity), 3), 'unit_price' => $line->unit_price];
            })->all();
        }
        return $resource;
    }

    private function orderTotal(PurchaseOrder $order): float
    {
        return round((float) $order->lines->sum(fn ($line) => round((float) $line->ordered_quantity * (float) $line->unit_price, 2)), 2);
    }

    private function paymentResource($payment): array
    {
        return ['code' => $payment->code, 'paid_at' => $payment->paid_at?->toDateString(), 'amount' => $payment->amount, 'financial_account_id' => $payment->financial_account_id,
            'reference' => $payment->reference, 'note' => $payment->note,
            'refunded_amount' => $this->decimal((float) $payment->refunds->sum('amount'), 2),
            'refund_status' => $payment->refunds->sum('amount') <= 0 ? 'NOT_REFUNDED' : ((float) $payment->refunds->sum('amount') + 0.001 >= (float) $payment->amount ? 'REFUNDED' : 'PARTIAL'),
            'refunds' => $payment->refunds->map(fn ($refund) => ['code' => $refund->code, 'refunded_at' => $refund->refunded_at?->toDateString(), 'amount' => $refund->amount, 'reference' => $refund->reference, 'note' => $refund->note])->all()];
    }

    private function receiptResource($receipt, $receiptItems): array
    {
        return ['code' => $receipt->code, 'received_at' => $receipt->received_at?->toDateString(), 'note' => $receipt->note,
            'supplier_payable_code' => $receipt->supplier_payable_code, 'supplier_credit_applied_amount' => $receipt->supplier_credit_applied_amount,
            'items' => $receiptItems->map(function ($receiptItem): array {
                $purchaseOrderItem = $receiptItem->relationLoaded('orderLine') ? $receiptItem->orderLine : $receiptItem->orderLine()->first();
                $receiptItem->loadMissing('inventoryItem');
                return ['code' => $receiptItem->code, 'purchase_order_item_code' => $purchaseOrderItem?->code,
                    'quantity' => $receiptItem->received_quantity, 'inventory_item_code' => $receiptItem->inventoryItem?->code,
                    'inventory_unit_codes' => $receiptItem->inventory_unit_codes ?? [], 'location_code' => $receiptItem->inventory_location_code,
                    'owned_item_code' => $receiptItem->owned_item_code, 'acquisition_lot_code' => $receiptItem->acquisition_lot_code,
                    'received_value' => $receiptItem->received_value, 'tracking_mode' => $purchaseOrderItem?->tracking_mode];
            })->all()];
    }
    private function returnResource($return, $returnItems): array
    {
        return ['code' => $return->code, 'returned_at' => $return->returned_at?->toDateString(), 'reason' => $return->reason,
            'note' => $return->note, 'items' => $returnItems->map(function ($returnItem): array {
                $receiptItem = $returnItem->relationLoaded('receiptLine') ? $returnItem->receiptLine : $returnItem->receiptLine()->first();
                return ['code' => $returnItem->code, 'purchase_receipt_item_code' => $receiptItem?->code,
                    'quantity' => $returnItem->returned_quantity, 'supplier_credit_amount' => $returnItem->supplier_credit_amount, 'supplier_credit_code' => $returnItem->supplier_credit_code,
                    'supplier_payable_adjusted_amount' => $returnItem->supplier_payable_adjusted_amount, 'inventory_unit_codes' => $returnItem->inventory_unit_codes ?? [],
                    'cash_refund_amount' => $returnItem->cash_refund_amount];
            })->all()];
    }
    // Record an accounting transaction using the source currency and operation reference.
    private function recordFinancialPosting(Model $reference, ?\App\Models\CoreModule\Currency $currency, string $direction, AccountingCategory $category, float $amount, string $description): \App\Models\TenantAccountingTransactions
    {
        return $this->accountingService->recordTransaction(new TenantAccountingTransactionRecord(
            reference: $reference, description: $description, transactionDirection: $direction,
            accountingCategory: $category, amount: $amount, createdBy: Auth::id(), currencyId: $currency?->id,
        ));
    }
    private function newCode(int $tenantId, string $table): string
    {
        return $this->codeGenerator->generateForTenant($tenantId, $table, CarbonImmutable::now());
    }

    private function decimal(float $value, int $scale): string
    {
        return number_format($value, $scale, '.', '');
    }

    private function validateTrackedQuantity(string $trackingMode, float $quantity): void
    {
        $valid = match ($trackingMode) {
            'UNIQUE' => abs($quantity - 1.0) < 0.0001,
            'SERIALIZED' => floor($quantity) === $quantity,
            default => true,
        };
        if (!$valid) {
            throw ValidationException::withMessages(['quantity' => [__('purchasing.quantity_for_tracking_mode')]]);
        }
    }

    private function runIdempotent(string $operation, array $payload, callable $callback, int $responseCode = 200, ?string $key = null): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        $record = $this->idempotencyService->reserveOptional($operation, $key, $payload);
        if ($record !== null && $this->idempotencyService->isReplay($record)) $this->idempotencyService->replay($record);
        try {
            return DB::transaction(function () use ($callback, $tenantId, $record, $responseCode): array {
                $result = $callback($tenantId);
                if ($record !== null) $this->idempotencyService->markCompleted($record, $responseCode, ['data' => $result]);
                return $result;
            });
        } catch (Throwable $exception) {
            if ($record !== null) $this->idempotencyService->markFailed($record);
            throw $exception;
        }
    }
}
