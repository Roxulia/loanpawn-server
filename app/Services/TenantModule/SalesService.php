<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\TenantAccountingTransactionRecord;
use App\Enums\AccountingCategory;
use App\Models\SalesModule\SaleOrder;
use App\Models\SalesModule\SaleOrderLine;
use App\Models\SalesModule\SalePayment;
use App\Repository\SalesRepository;
use App\Services\BaseTenantService;
use App\Services\DeliveryModule\DeliveryOrderChargeService;
use App\Services\TableIdGenerationService;
use App\Services\TenantModule\Accounting\FinancialAccountTransactionService;
use App\Services\TenantModule\Accounting\MultiAccountManagement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class SalesService extends BaseTenantService
{
    public function __construct(
        private SalesRepository $repository,
        private InventoryService $inventoryService,
        private OwnershipService $ownershipService,
        private SaleReceivableService $saleReceivableService,
        private TenantCurrencyService $currencyService,
        private TenantIdempotencyService $idempotencyService,
        private TableIdGenerationService $codeGenerator,
        private MultiAccountManagement $accountManagement,
        private TenantAccountingTransactionService $accountingService,
        private FinancialAccountTransactionService $accountTransactionService,
        private DeliveryOrderChargeService $deliveryChargeService,
    ) {}

    public function orders(?string $search = null): array
    {
        // Return tenant-scoped Sales orders using public resource fields.
        return $this->repository->orders($this->resolveCurrentTenantId(), $search)
            ->map(fn (SaleOrder $order) => $this->orderResource($order))->all();
    }

    // Resolve a tenant sale for Delivery module coordination without exposing internal IDs in public resources.
    public function orderForDelivery(string $code): SaleOrder
    {
        $order = $this->repository->orderByCode($this->resolveCurrentTenantId(), $code, true);
        if ($order === null) throw ValidationException::withMessages(['sale_code' => [__('sales.order_not_found')]]);
        return $order;
    }
    public function order(string $code): array
    {
        // Resolve a Sale by its tenant business code.
        $order = $this->repository->orderByCode($this->resolveCurrentTenantId(), $code);
        if ($order === null) {
            throw ValidationException::withMessages(['sale_code' => [__('sales.order_not_found')]]);
        }
        return $this->orderResource($order, true);
    }

    public function createOrder(array $data, ?string $idempotencyKey): array
    {
        // Create a draft Sale without changing stock, ownership, or balances.
        return $this->runIdempotent('sales.order.create', $data, function (int $tenantId) use ($data): array {
            $currency = $this->currencyService->findActiveVisibleByCodeForTenant($tenantId, strtoupper($data['currency_code']));
            if ($currency === null) {
                throw ValidationException::withMessages(['currency_code' => [__('sales.currency_not_found')]]);
            }
            $customer = isset($data['customer_code']) ? $this->repository->customerByCode($tenantId, $data['customer_code']) : null;
            if (isset($data['customer_code']) && $customer === null) {
                throw ValidationException::withMessages(['customer_code' => [__('sales.customer_not_found')]]);
            }
            $order = $this->repository->createOrder($tenantId, [
                'code' => $this->newCode($tenantId, 'sales_orders'), 'customer_id' => $customer?->id,
                'status' => 'DRAFT', 'sold_at' => $data['sold_at'], 'currency_code' => strtoupper($currency->code),
                'note' => $data['note'] ?? null, 'created_by' => $this->currentUserId(),
            ]);
            foreach ($data['items'] as $line) {
                $item = $this->inventoryService->findForOwnershipByCode($line['inventory_item_code']);
                $lots = $this->ownershipService->fifoLots($line['owned_item_code']);
                $owned = $this->ownershipService->detailByCode($line['owned_item_code']);
                if ($item->code !== $line['inventory_item_code'] || $owned['inventory_item_code'] !== $item->code) {
                    throw ValidationException::withMessages(['items' => [__('sales.owned_item_unavailable')]]);
                }
                $quantity = (float) $line['quantity'];
                $this->validateQuantity($item->tracking_mode, $quantity);
                if (! collect($lots)->contains(fn (array $lot): bool => strtoupper($lot['currency_code']) === strtoupper($currency->code))) {
                    throw ValidationException::withMessages(['currency_code' => [__('sales.currency_not_found')]]);
                }
                $this->repository->createLine($tenantId, (int) $order->id, [
                    'code' => $this->newCode($tenantId, 'sales_order_lines'), 'inventory_item_id' => $item->id,
                    'owned_item_id' => $owned['id'], 'item_description' => $item->name,
                    'tracking_mode' => $item->tracking_mode, 'unit_label' => $item->unit?->name ?? 'unit',
                    'ordered_quantity' => $quantity, 'unit_price' => $line['unit_price'],
                ]);
            }
            return $this->orderResource($this->repository->orderByCode($tenantId, $order->code), true);
        }, 201, $idempotencyKey);
    }

    // Update a draft Sale atomically without changing reservations, balances, or delivery history.
    public function updateDraftOrder(string $code, array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('sales.order.update', ['sale_code' => $code] + $data, function (int $tenantId) use ($code, $data): array {
            return DB::transaction(function () use ($tenantId, $code, $data): array {
                $order = $this->repository->orderByCode($tenantId, $code, true);
                if ($order === null) throw ValidationException::withMessages(['sale_code' => [__('sales.order_not_found')]]);
                if ($order->status !== 'DRAFT' || $order->payments->isNotEmpty() || $order->deliveries->isNotEmpty()) {
                    throw ValidationException::withMessages(['status' => ['Only an untouched draft sale can be edited.']]);
                }
                $currency = $this->currencyService->findActiveVisibleByCodeForTenant($tenantId, strtoupper($data['currency_code']));
                if ($currency === null) throw ValidationException::withMessages(['currency_code' => [__('sales.currency_not_found')]]);
                $customer = isset($data['customer_code']) ? $this->repository->customerByCode($tenantId, $data['customer_code']) : null;
                if (isset($data['customer_code']) && $customer === null) throw ValidationException::withMessages(['customer_code' => [__('sales.customer_not_found')]]);
                $this->repository->updateOrder($order, [
                    'customer_id' => $customer?->id, 'currency_code' => strtoupper($currency->code),
                    'sold_at' => $data['sold_at'], 'note' => $data['note'] ?? null,
                ]);
                $this->repository->deleteLines($tenantId, (int) $order->id);
                foreach ($data['items'] as $line) {
                    $item = $this->inventoryService->findForOwnershipByCode($line['inventory_item_code']);
                    $owned = $this->ownershipService->detailByCode($line['owned_item_code']);
                    $lots = $this->ownershipService->fifoLots($line['owned_item_code']);
                    if ($owned['inventory_item_code'] !== $item->code || !collect($lots)->contains(fn (array $lot): bool => strtoupper($lot['currency_code']) === strtoupper($currency->code))) {
                        throw ValidationException::withMessages(['items' => [__('sales.owned_item_unavailable')]]);
                    }
                    $quantity = (float) $line['quantity'];
                    $this->validateQuantity($item->tracking_mode, $quantity);
                    $this->repository->createLine($tenantId, (int) $order->id, [
                        'code' => $this->newCode($tenantId, 'sales_order_lines'), 'inventory_item_id' => $item->id,
                        'owned_item_id' => $owned['id'], 'item_description' => $item->name,
                        'tracking_mode' => $item->tracking_mode, 'unit_label' => $item->unit?->name ?? 'unit',
                        'ordered_quantity' => $quantity, 'unit_price' => $line['unit_price'],
                    ]);
                }
                return $this->orderResource($this->repository->orderByCode($tenantId, $code), true);
            });
        }, 200, $idempotencyKey);
    }
    public function transition(string $code, string $action, ?string $idempotencyKey): array
    {
        // Confirm orders by reserving stock and lots; cancel releases undelivered reservations.
        return $this->runIdempotent('sales.order.'.$action, ['sale_code' => $code], function (int $tenantId) use ($code, $action): array {
            return DB::transaction(function () use ($tenantId, $code, $action): array {
                $order = $this->repository->orderByCode($tenantId, $code, true);
                if ($order === null) {
                    throw ValidationException::withMessages(['sale_code' => [__('sales.order_not_found')]]);
                }
                if ($action === 'confirm' && $order->status === 'DRAFT') {
                    foreach ($order->lines as $line) {
                        $this->reserveLine($tenantId, $line);
                    }
                    $order->update(['status' => 'CONFIRMED']);
                } elseif ($action === 'cancel' && $order->status === 'CONFIRMED' && $this->deliveredQuantity($order) <= 0.001) {
                    foreach ($order->lines as $line) {
                        foreach ($this->repository->reservationsForLine($tenantId, (int) $line->id, true) as $reservation) {
                            $quantity = (float) $reservation->remaining_quantity;
                            $this->inventoryService->releaseSaleReservation($reservation->inventory_reservation_code);
                            $this->ownershipService->releaseSaleReservation(
                                $line->ownedItem->code, $reservation->lot->code, $quantity, $line->code,
                            );
                            $this->repository->updateReservation($reservation, ['remaining_quantity' => 0, 'status' => 'RELEASED']);
                        }
                    }
                    $order->update(['status' => 'CANCELLED']);
                } else {
                    throw ValidationException::withMessages(['status' => [__('sales.invalid_order_transition')]]);
                }
                return $this->orderResource($this->repository->orderByCode($tenantId, $code), true);
            });
        }, 200, $idempotencyKey);
    }

    public function recordPayment(string $code, array $data, ?string $idempotencyKey): array
    {
        // Apply the tender to outstanding delivered and undelivered sale value, returning any excess as change.
        return $this->runIdempotent('sales.payment.create', ['sale_code' => $code] + $data, function (int $tenantId) use ($code, $data): array {
            $order = $this->repository->orderByCode($tenantId, $code, true);
            if ($order === null || ! in_array($order->status, ['CONFIRMED', 'COMPLETED'], true)) {
                throw ValidationException::withMessages(['sale_code' => [__('sales.invalid_order_transition')]]);
            }
            $account = $this->accountManagement->findActiveCurrentTenantAccount((int) $data['financial_account_id']);
            if (strtoupper((string) $account->currency?->code) !== strtoupper($order->currency_code)) {
                throw ValidationException::withMessages(['financial_account_id' => [__('sales.account_currency_mismatch')]]);
            }
            $total = $this->orderTotal($order);
            $netPaid = $this->netPrepayments($tenantId, (int) $order->id);
            $receivablePaid = $this->saleReceivableService->paidForOrder((int) $order->id);
            $maximum = max(0, round($total - $netPaid - $receivablePaid, 2));
            $tendered = round((float) $data['amount'], 2);
            if ($maximum <= 0.001) {
                throw ValidationException::withMessages(['amount' => [__('sales.payment_exceeds_balance')]]);
            }
            $amount = min($tendered, $maximum);
            $change = round($tendered - $amount, 2);
            $receivableBalance = $this->saleReceivableService->balanceForOrder((int) $order->id);
            $receivablePayment = 0.0;
            if ($receivableBalance > 0) {
                $receivablePayment = min($amount, $receivableBalance);
                $collectionData = $data + ['payment_amount' => $receivablePayment, 'change_amount' => 0];
                if ($amount <= $receivableBalance + 0.001) {
                    $collectionData['payment_amount'] = $tendered;
                    $collectionData['change_amount'] = $change;
                }
                $this->saleReceivableService->collectForOrder($order, $receivablePayment, $collectionData, (int) $account->id);
            }
            $advance = round($amount - $receivablePayment, 2);
            if ($advance > 0) {
                $payment = $this->repository->createPayment($tenantId, [
                    'code' => $this->newCode($tenantId, 'sales_payments'), 'sales_order_id' => $order->id,
                    'financial_account_id' => $account->id, 'paid_at' => $data['paid_at'], 'amount' => $advance,
                    'payment_amount' => round($advance + $change, 2), 'change_amount' => $change,
                    'reference' => $data['reference'] ?? null, 'note' => $data['note'] ?? null,
                    'created_by' => $this->currentUserId(),
                ]);
                $ledger = $this->recordFinancialPosting($payment, $account->currency, 'incoming', AccountingCategory::Liability, $advance, 'Customer deposit for sale '.$order->code);
                $this->repository->updatePaymentAccounting($payment, (int) $ledger->id);
                $this->accountTransactionService->recordAdjustment($account, (float) $payment->payment_amount, 'debit', $payment->code, SalePayment::class, 'Sale payment received', $this->currentUserId(), $ledger->id);
                $this->recordSaleChange($payment, $account, $change);
            }
            return $this->orderResource($this->repository->orderByCode($tenantId, $code), true);
        }, 201, $idempotencyKey);
    }

    // Record a customer collection with the same retry protection as sale payments.
    public function recordReceivablePayment(string $code, array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('sales.receivable.payment.create', ['receivable_code' => $code] + $data,
            fn (int $tenantId) => $this->saleReceivableService->collect($code, (float) $data['amount'], $data, (int) $data['financial_account_id']),
            201, $idempotencyKey);
    }

    // Complete a counter sale atomically without creating a Delivery-module dispatch.
    public function quickSale(array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('sales.quick.create', $data, function (int $tenantId) use ($data, $idempotencyKey): array {
            $order = $this->createOrder($data, null);
            $code = $order['code'];
            $this->transition($code, 'confirm', null);
            if ((float) ($data['tender_amount'] ?? 0) > 0) {
                $this->recordPayment($code, [
                    'financial_account_id' => (int) $data['financial_account_id'],
                    'amount' => (float) $data['tender_amount'], 'paid_at' => $data['sold_at'],
                ], null);
            }
            return $this->completeImmediately($code, ['delivered_at' => $data['sold_at']], null);
        }, 201, $idempotencyKey);
    }

    // Issue all reserved stock as a direct counter sale and create receivables for the unpaid remainder.
    public function completeImmediately(string $code, array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('sales.immediate.fulfillment', ['sale_code' => $code] + $data, function (int $tenantId) use ($code, $data): array {
            return DB::transaction(function () use ($tenantId, $code, $data): array {
                $order = $this->repository->orderByCode($tenantId, $code, true);
                if ($order === null || $order->status !== 'CONFIRMED') {
                    throw ValidationException::withMessages(['sale_code' => [__('sales.invalid_order_transition')]]);
                }
                $saleDelivery = $this->repository->createDelivery($tenantId, [
                    'code' => $this->newCode($tenantId, 'sales_deliveries'), 'sales_order_id' => $order->id,
                    'delivered_at' => $data['delivered_at'], 'note' => 'Immediate counter sale', 'created_by' => $this->currentUserId(),
                ]);
                $deliveredValue = 0.0;
                foreach ($order->lines as $line) {
                    $reservations = $this->repository->reservationsForLine($tenantId, (int) $line->id, true);
                    foreach ($reservations as $reservation) {
                        $take = (float) $reservation->remaining_quantity;
                        if ($take <= 0.001) continue;
                        $unitCodes = $line->tracking_mode === 'SERIALIZED' ? ($reservation->inventory_unit_codes ?? []) : [];
                        $allocation = $this->repository->createDeliveryAllocation($tenantId, [
                            'code' => $this->newCode($tenantId, 'sales_delivery_allocations'),
                            'sales_delivery_id' => $saleDelivery->id, 'delivery_id' => null,
                            'sales_order_line_id' => $line->id, 'acquisition_lot_id' => $reservation->acquisition_lot_id,
                            'inventory_location_id' => $reservation->inventory_location_id, 'quantity' => $take,
                            'unit_cost' => $reservation->lot->unit_cost_basis, 'unit_price' => $line->unit_price,
                            'inventory_unit_codes' => $unitCodes,
                        ]);
                        $cost = round($take * (float) $reservation->lot->unit_cost_basis, 2);
                        $currency = $this->currencyService->findActiveVisibleByCodeForTenant($tenantId, $order->currency_code);
                        $expense = $this->recordFinancialPosting($allocation, $currency, 'outgoing', AccountingCategory::Expense, $cost, 'Cost of goods sold for immediate sale '.$allocation->code);
                        $asset = $this->recordFinancialPosting($allocation, $currency, 'outgoing', AccountingCategory::Asset, $cost, 'Inventory asset relieved for immediate sale '.$allocation->code);
                        $this->repository->updateAllocationAccounting($allocation, (int) $expense->id, (int) $asset->id);
                        $this->inventoryService->issue([
                            'inventory_item_code' => $line->inventoryItem->code,
                            'location_code' => $reservation->inventory_location_code,
                            'quantity' => $take, 'inventory_unit_codes' => $unitCodes,
                            'reservation_code' => $reservation->inventory_reservation_code,
                            'source_type' => 'SaleDeliveryAllocation', 'source_code' => $allocation->code,
                        ], 'quick-sale-'.$allocation->code);
                        $this->ownershipService->releaseSaleReservation($line->ownedItem->code, $reservation->lot->code, $take, $line->code);
                        $this->ownershipService->reduceForSale($line->ownedItem->code, $reservation->lot->code, $take, 'SaleLine', $allocation->code, 'quick-sale-ownership-'.$allocation->code);
                        $this->repository->updateReservation($reservation, ['remaining_quantity' => 0, 'status' => 'CONSUMED', 'inventory_unit_codes' => []]);
                        $deliveredValue += round($take * (float) $line->unit_price, 2);
                    }
                }
                $prepaid = min($deliveredValue, $this->netPrepayments($tenantId, (int) $order->id));
                $unpaid = max(0, round($deliveredValue - $prepaid, 2));
                $this->saleReceivableService->createForSaleDelivery($order, (int) $saleDelivery->id, $unpaid);
                $currency = $this->currencyService->findActiveVisibleByCodeForTenant($tenantId, $order->currency_code);
                if ($prepaid > 0.001) {
                    $this->recordFinancialPosting($saleDelivery, $currency, 'outgoing', AccountingCategory::Liability, $prepaid, 'Customer deposit applied to immediate sale '.$order->code);
                }
                if ($unpaid > 0.001) {
                    $this->recordFinancialPosting($saleDelivery, $currency, 'incoming', AccountingCategory::Asset, $unpaid, 'Customer receivable for immediate sale '.$order->code);
                }
                if ($deliveredValue > 0.001) {
                    $this->recordFinancialPosting($saleDelivery, $currency, 'incoming', AccountingCategory::Revenue, $deliveredValue, 'Immediate sale revenue '.$order->code);
                }
                $order->update(['status' => 'COMPLETED']);
                return $this->orderResource($this->repository->orderByCode($tenantId, $code), true);
            });
        }, 201, $idempotencyKey);
    }
    public function recordDelivery(string $code, array $data, ?string $idempotencyKey): array
    {
        // Fulfill reserved lots FIFO and update stock, cost history, and customer receivable atomically.
        return $this->runIdempotent('sales.delivery.create', ['sale_code' => $code] + $data, function (int $tenantId) use ($code, $data): array {
            return DB::transaction(function () use ($tenantId, $code, $data): array {
                $order = $this->repository->orderByCode($tenantId, $code, true);
                if ($order === null || $order->status !== 'CONFIRMED') {
                    throw ValidationException::withMessages(['sale_code' => [__('sales.invalid_order_transition')]]);
                }
                $delivery = $this->deliveryChargeService->deliveryById($tenantId, (int) $data['delivery_id']);
                if ($delivery === null) throw ValidationException::withMessages(['delivery_id' => ['Delivery record not found.']]);
                $deliveredValue = 0.0;
                foreach ($data['items'] as $itemData) {
                    $line = $order->lines->firstWhere('code', $itemData['sales_order_line_code']);
                    if ($line === null) throw ValidationException::withMessages(['items' => [__('sales.line_not_found')]]);
                    $quantity = (float) $itemData['quantity'];
                    $this->validateQuantity($line->tracking_mode, $quantity);
                    $reservations = $this->repository->reservationsForLine($tenantId, (int) $line->id, true);
                    $reservedQuantity = (float) $reservations->sum('remaining_quantity');
                    if ($quantity > $reservedQuantity + 0.001) {
                        throw ValidationException::withMessages(['items' => [__('sales.delivery_exceeds_reserved')]]);
                    }
                    $left = $quantity;
                    foreach ($reservations as $reservation) {
                        if ($left <= 0.001) break;
                        $take = min($left, (float) $reservation->remaining_quantity);
                        $unitCodes = $line->tracking_mode === 'SERIALIZED'
                            ? array_slice($reservation->inventory_unit_codes ?? [], 0, (int) $take)
                            : [];
                        $allocation = $this->repository->createDeliveryAllocation($tenantId, [
                            'code' => $this->newCode($tenantId, 'sales_delivery_allocations'),
                            'sales_delivery_id' => null, 'delivery_id' => (int) $data['delivery_id'], 'sales_order_line_id' => $line->id,
                            'acquisition_lot_id' => $reservation->acquisition_lot_id,
                            'inventory_location_id' => $reservation->inventory_location_id,
                            'quantity' => $take, 'unit_cost' => $reservation->lot->unit_cost_basis,
                            'unit_price' => $line->unit_price, 'inventory_unit_codes' => $unitCodes,
                        ]);
                        // Post the FIFO lot cost as COGS and inventory asset relief.
                        $costAmount = round($take * (float) $reservation->lot->unit_cost_basis, 2);
                        $currency = $this->currencyService->findActiveVisibleByCodeForTenant($tenantId, $order->currency_code);
                        $expenseLedger = $this->recordFinancialPosting($allocation, $currency, 'outgoing', AccountingCategory::Expense, $costAmount, 'Cost of goods sold for delivery allocation '.$allocation->code);
                        $assetLedger = $this->recordFinancialPosting($allocation, $currency, 'outgoing', AccountingCategory::Asset, $costAmount, 'Inventory asset relieved for delivery allocation '.$allocation->code);
                        $this->repository->updateAllocationAccounting($allocation, (int) $expenseLedger->id, (int) $assetLedger->id);
                        $this->inventoryService->issue([
                            'inventory_item_code' => $line->inventoryItem->code,
                            'location_code' => $reservation->inventory_location_code,
                            'quantity' => $take, 'inventory_unit_codes' => $unitCodes,
                            'reservation_code' => $reservation->inventory_reservation_code,
                            'source_type' => 'SaleDeliveryAllocation', 'source_code' => $allocation->code,
                        ], 'sales-delivery-'.$allocation->code);
                        $this->ownershipService->releaseSaleReservation($line->ownedItem->code, $reservation->lot->code, $take, $line->code);
                        $this->ownershipService->reduceForSale($line->ownedItem->code, $reservation->lot->code, $take, 'SaleLine', $allocation->code, 'sales-ownership-'.$allocation->code);
                        $remaining = max(0, (float) $reservation->remaining_quantity - $take);
                        $remainingUnits = array_slice($reservation->inventory_unit_codes ?? [], (int) $take);
                        $this->repository->updateReservation($reservation, [
                            'remaining_quantity' => $remaining, 'status' => $remaining <= 0.001 ? 'CONSUMED' : 'ACTIVE',
                            'inventory_unit_codes' => $remainingUnits,
                        ]);
                        $deliveredValue += round($take * (float) $line->unit_price, 2);
                        $left -= $take;
                    }
                }
                $order = $this->repository->orderByCode($tenantId, $code);
                $deliveryFee = $this->deliveryChargeService->recognizeForOrder($tenantId, (int) $order->id, (int) $delivery->id);
                $deliveredValue += $deliveryFee;
                $depositApplied = $this->syncSaleReceivable($tenantId, $order, (int) $delivery->id, $deliveredValue);
                if ($depositApplied > 0.001) {
                    // Release the deposit liability as its sale value is recognized at delivery.
                    $currency = $this->currencyService->findActiveVisibleByCodeForTenant($tenantId, $order->currency_code);
                    $this->recordFinancialPosting($delivery, $currency, 'outgoing', AccountingCategory::Liability, $depositApplied, 'Customer deposit applied to sale '.$order->code);
                    $this->recordFinancialPosting($delivery, $currency, 'incoming', AccountingCategory::Revenue, $depositApplied, 'Delivered sale deposit '.$order->code);
                }
                if ($this->deliveredQuantity($order) + 0.001 >= $this->orderedQuantity($order)) {
                    $order->update(['status' => 'COMPLETED']);
                }
                return $this->orderResource($this->repository->orderByCode($tenantId, $code), true);
            });
        }, 201, $idempotencyKey);
    }

    private function reserveLine(int $tenantId, SaleOrderLine $line): void
    {
        // Reserve FIFO ownership lots against actual physical locations before confirmation commits.
        $item = $this->inventoryService->detailByCode($line->inventoryItem->code);
        $lots = $this->ownershipService->fifoLots($line->ownedItem->code);
        $remaining = (float) $line->ordered_quantity;
        $locations = collect($item['locations'])->sortBy('location_code')->values();
        $locationRemaining = $locations->mapWithKeys(fn (array $location): array => [
            $location['location_code'] => (float) ($location['available_quantity'] ?? $location['quantity']),
        ])->all();
        foreach ($lots as $lot) {
            if ($remaining <= 0.001) break;
            $lotRemaining = min($remaining, (float) $lot['available_quantity']);
            foreach ($locations as $location) {
                if ($lotRemaining <= 0.001) break;
                $locationAvailable = (float) ($locationRemaining[$location['location_code']] ?? 0);
                if ($locationAvailable <= 0.001) continue;
                $take = min($lotRemaining, $locationAvailable);
                $unitCodes = [];
                if ($line->tracking_mode === 'SERIALIZED') {
                    $unitCodes = collect($item['units'])->where('location_code', $location['location_code'])->where('is_reserved', false)->pluck('code')->take((int) $take)->values()->all();
                    $take = (float) count($unitCodes);
                    if ($take <= 0) continue;
                }
                $inventoryReservation = $this->inventoryService->reserveForSale([
                    'inventory_item_code' => $line->inventoryItem->code, 'location_code' => $location['location_code'],
                    'quantity' => $take, 'inventory_unit_codes' => $unitCodes, 'source_code' => $line->code,
                ], 'sales-reserve-'.$line->code.'-'.$lot['code'].'-'.$location['location_code']);
                $this->ownershipService->reserveSale($line->ownedItem->code, $lot['code'], $take, $line->code);
                $this->repository->createReservation($tenantId, (int) $line->id, [
                    'code' => $this->newCode($tenantId, 'sales_order_reservations'),
                    'acquisition_lot_id' => $lot['id'],
                    'inventory_location_id' => $location['location_id'],
                    'inventory_location_code' => $location['location_code'],
                    'inventory_reservation_code' => $inventoryReservation['code'],
                    'quantity' => $take, 'remaining_quantity' => $take, 'inventory_unit_codes' => $unitCodes,
                    'status' => 'ACTIVE',
                ]);
                $remaining -= $take;
                $lotRemaining -= $take;
                $locationRemaining[$location['location_code']] = $locationAvailable - $take;
            }
        }
        if ($remaining > 0.001) {
            throw ValidationException::withMessages(['quantity' => [__('sales.insufficient_lots')]]);
        }
    }

    private function syncSaleReceivable(int $tenantId, SaleOrder $order, int $deliveryId, float $newDeliveryValue): float
    {
        // Apply unused order deposits to this delivery before creating its dedicated receivable.
        $alreadyDelivered = max(0, $this->deliveredValue($order) - $newDeliveryValue);
        $prepaid = $this->netPrepayments($tenantId, (int) $order->id);
        $depositAppliedToEarlierDeliveries = max(0, $alreadyDelivered - $this->saleReceivableService->originalForOrder((int) $order->id));
        $unusedDeposit = max(0, $prepaid - $depositAppliedToEarlierDeliveries);
        $unpaidDeliveredValue = max(0, round($newDeliveryValue - $unusedDeposit, 2));
        $this->saleReceivableService->createForDelivery($order, $deliveryId, $unpaidDeliveredValue);
        return max(0, round($newDeliveryValue - $unpaidDeliveredValue, 2));
    }

    private function orderResource(SaleOrder $order, bool $details = false): array
    {
        // Derive payment and delivery progress without treating payment as stock issue.
        $items = $order->lines->map(function (SaleOrderLine $line): array {
            $delivered = $this->repository->deliveredForLine((int) $line->tenant_id, (int) $line->id);
            $reserved = $this->repository->reservedForLine((int) $line->tenant_id, (int) $line->id);
            return [
                'code' => $line->code, 'inventory_item_code' => $line->inventoryItem->code,
                'owned_item_code' => $line->ownedItem->code, 'description' => $line->item_description,
                'tracking_mode' => $line->tracking_mode, 'unit_label' => $line->unit_label,
                'ordered_quantity' => $line->ordered_quantity, 'delivered_quantity' => $this->decimal($delivered),
                'undelivered_quantity' => $this->decimal(max(0, (float) $line->ordered_quantity - $delivered)),
                'reserved_quantity' => $this->decimal($reserved), 'unit_price' => $line->unit_price,
                'line_total' => round((float) $line->ordered_quantity * (float) $line->unit_price, 2),
            ];
        });
        // Include delivery fees in order total and recognized fulfillment value.
        $deliveryFee = $this->deliveryChargeService->amountForOrder((int) $order->id);
        $recognizedDeliveryFee = $this->deliveryChargeService->recognizedAmountForOrder((int) $order->id);
        $total = (float) $items->sum('line_total') + $deliveryFee;
        $delivered = (float) $items->sum(fn (array $item) => (float) $item['delivered_quantity'] * (float) $item['unit_price']) + $recognizedDeliveryFee;
        $deliveredQuantity = (float) $items->sum(fn (array $item) => (float) $item['delivered_quantity']);
        $depositPaid = $this->netPrepayments((int) $order->tenant_id, (int) $order->id);
        $receivablePaid = $this->saleReceivableService->paidForOrder((int) $order->id);
        $paid = $depositPaid + $receivablePaid;
        $receivableBalance = $this->saleReceivableService->balanceForOrder((int) $order->id);
        $resource = [
            'code' => $order->code, 'status' => $order->status, 'sold_at' => $order->sold_at?->toDateString(),
            'customer' => $order->customer ? ['code' => $order->customer->code, 'name' => $order->customer->name] : null,
            'currency_code' => $order->currency_code, 'note' => $order->note, 'delivery_fee' => number_format($deliveryFee, 2, '.', ''),
            'payment_status' => $paid + 0.001 >= $total ? 'PAID' : ($paid <= 0.001 ? 'UNPAID' : 'PARTIAL'),
            'delivery_status' => $deliveredQuantity <= 0.001 ? 'NOT_DELIVERED' : ($deliveredQuantity + 0.001 >= (float) $items->sum('ordered_quantity') ? 'DELIVERED' : 'PARTIAL'),
            'totals' => ['amount' => round($total, 2), 'delivery_fee' => round($deliveryFee, 2), 'delivered_value' => round($delivered, 2), 'paid_amount' => round($paid, 2),
                'receivable_amount' => $receivableBalance,
                'undelivered_quantity' => $this->decimal((float) $items->sum('undelivered_quantity'))],
            'items' => $items->all(),
        ];
        if ($details) {
            $resource['payments'] = $order->payments->map(fn ($payment) => [
                'code' => $payment->code, 'paid_at' => $payment->paid_at?->toDateString(), 'amount' => $payment->amount,
                'payment_amount' => $payment->payment_amount, 'change_amount' => $payment->change_amount,
                'reference' => $payment->reference,
            ])->all();
            $resource['deliveries'] = $order->deliveries->map(fn ($delivery) => [
                'code' => $delivery->code, 'delivered_at' => $delivery->delivered_at?->toDateString(),
                'items' => $delivery->allocations->map(fn ($allocation) => [
                    'code' => $allocation->code, 'sale_line_code' => $allocation->line?->code,
                    'acquisition_lot_code' => $allocation->lot?->code, 'quantity' => $allocation->quantity,
                    'unit_cost' => $allocation->unit_cost, 'unit_price' => $allocation->unit_price,
                ])->all(),
            ])->all();
            $resource['receivables'] = $this->saleReceivableService->listForOrder((int) $order->id);
            $resource['return_allocations'] = $this->repository->allocationsForOrder((int) $order->tenant_id, (int) $order->id)->map(fn ($allocation): array => [
                'code' => $allocation->code, 'item_description' => $allocation->line?->item_description,
                'tracking_mode' => $allocation->line?->tracking_mode, 'quantity' => (string) $allocation->quantity,
                'returned_quantity' => (string) $this->repository->returnedForAllocation((int) $order->tenant_id, (int) $allocation->id),
                'unit_codes' => array_values(array_diff($allocation->inventory_unit_codes ?? [], $this->repository->returnedUnitCodesForAllocation((int) $order->tenant_id, (int) $allocation->id))),
            ])->all();
        }
        return $resource;
    }

    private function recordFinancialPosting($reference, $currency, string $direction, AccountingCategory $category, float $amount, string $description)
    {
        // Reuse the tenant Accounting service for financial activity.
        return $this->accountingService->recordTransaction(new TenantAccountingTransactionRecord(
            reference: $reference, description: $description, transactionDirection: $direction,
            accountingCategory: $category, amount: $amount, createdBy: $this->currentUserId(), currencyId: $currency?->id,
        ));
    }

    // Post returned sale overpayment as an outgoing asset and financial-account movement.
    private function recordSaleChange(SalePayment $payment, $account, float $change): void
    {
        if ($change <= 0.001) return;
        $ledger = $this->recordFinancialPosting($payment, $account->currency, 'outgoing', AccountingCategory::Asset, $change, 'Change returned for sale payment '.$payment->code);
        $this->accountTransactionService->recordAdjustment($account, $change, 'credit', $payment->code, SalePayment::class, 'Sale payment change', $this->currentUserId(), $ledger->id);
    }

    private function runIdempotent(string $operation, array $payload, callable $callback, int $responseCode, ?string $key): array
    {
        // Keep retries safe around order, payment, and delivery records.
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

    private function orderTotal(SaleOrder $order): float
    {
        return round((float) $order->lines->sum(fn (SaleOrderLine $line) => (float) $line->ordered_quantity * (float) $line->unit_price) + $this->deliveryChargeService->amountForOrder((int) $order->id), 2);
    }

    private function netPrepayments(int $tenantId, int $orderId): float
    {
        return (float) $this->repository->paymentsForOrder($tenantId, $orderId)->sum(fn ($payment) => (float) $payment->amount - (float) $payment->refunds->sum('amount'));
    }

    private function deliveredValue(SaleOrder $order): float
    {
        return round((float) $order->deliveries->sum(fn ($delivery) => $delivery->allocations->sum(fn ($allocation) => (float) $allocation->quantity * (float) $allocation->unit_price)) + $this->deliveryChargeService->recognizedAmountForOrder((int) $order->id), 2);
    }

    private function deliveredQuantity(SaleOrder $order): float
    {
        return (float) $order->deliveries->sum(fn ($delivery) => $delivery->allocations->sum('quantity'));
    }

    private function orderedQuantity(SaleOrder $order): float
    {
        return (float) $order->lines->sum('ordered_quantity');
    }

    private function validateQuantity(string $trackingMode, float $quantity): void
    {
        if (! is_finite($quantity) || $quantity <= 0 || ($trackingMode !== 'QUANTITY' && abs($quantity - round($quantity)) > 0.001)) {
            throw ValidationException::withMessages(['quantity' => [__('sales.quantity_invalid')]]);
        }
    }

    private function newCode(int $tenantId, string $table): string
    {
        return $this->codeGenerator->generateForTenant($tenantId, $table, CarbonImmutable::now());
    }

    private function decimal(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

    private function currentUserId(): ?int
    {
        return Auth::guard('tenantuser')->id() ?? Auth::id();
    }

}
