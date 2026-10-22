<?php

namespace App\Repository;

use App\Models\PurchasingModule\PurchaseOrder;
use App\Models\PurchasingModule\PurchaseOrderLine;
use App\Models\PurchasingModule\PurchasePayment;
use App\Models\PurchasingModule\PurchaseReceipt;
use App\Models\PurchasingModule\PurchaseReceiptLine;
use App\Models\PurchasingModule\PurchaseRefund;
use App\Models\PurchasingModule\PurchaseReturn;
use App\Models\PurchasingModule\PurchaseReturnLine;
use App\Models\PurchasingModule\PurchaseSupplier;
use App\Models\CoreModule\TenantPerson;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PurchasingRepository
{
    public function ownershipLine(int $tenantId, string $code, bool $lock = false): ?PurchaseOrderLine
    {
        // Purchase-source resolution remains in its owning repository/service boundary.
        return PurchaseOrderLine::query()->where('tenant_id', $tenantId)->where('code', $code)
            ->when($lock, fn ($q) => $q->lockForUpdate())->with('order')->first();
    }

    public function ownershipPaymentCurrencyIds(int $tenantId, int $orderId): array
    {
        // Retain the actual currency identity of settled payments even if its code has another visible match.
        return DB::table('purchase_payments as payment')->join('financial_accounts as account', function ($join): void {
            $join->on('account.id', '=', 'payment.financial_account_id')->on('account.tenant_id', '=', 'payment.tenant_id');
        })->where('payment.tenant_id', $tenantId)->where('payment.purchase_order_id', $orderId)
            ->distinct()->pluck('account.currency_id')->all();
    }

    public function ownershipSettlement(int $tenantId, int $orderId): object
    {
        // Compare exact SQL DECIMAL aggregates without PHP floating-point money arithmetic.
        $bill = PurchaseOrderLine::query()->where('tenant_id', $tenantId)->where('purchase_order_id', $orderId)
            ->selectRaw('COALESCE(SUM(ordered_quantity * unit_price), 0) AS total');
        $paid = PurchasePayment::query()->where('tenant_id', $tenantId)->where('purchase_order_id', $orderId)
            ->selectRaw('COALESCE(SUM(amount), 0) AS total, MAX(paid_at) AS settled_at');
        return DB::query()->fromSub($bill, 'bill')->crossJoinSub($paid, 'paid')
            ->selectRaw('paid.total >= bill.total AS settled, bill.total = 0 AS free, paid.settled_at')->first();
    }

    public function ownershipReceiptPage(int $tenantId, string $lineCode, $filter)
    {
        // Scope receipt candidates to their canonical purchase line before applying bounded pagination.
        return PurchaseReceiptLine::query()->where('tenant_id', $tenantId)
            ->whereHas('orderLine', fn ($q) => $q->where('tenant_id', $tenantId)->where('code', $lineCode))
            ->when($filter->search !== null, fn ($q) => $q->where('code', 'like', '%'.$filter->search.'%'))
            ->with(['receipt', 'orderLine'])->orderBy('id')->paginate($filter->perPage, ['*'], 'page', $filter->page);
    }

    public function ownershipReceivedQuantities(int $tenantId, array $lineCodes): \Illuminate\Support\Collection
    {
        // Batch source receipt totals for item and lot read projections.
        return DB::table('purchase_receipt_lines as receipt')->join('purchase_order_lines as line', function ($join): void {
            $join->on('line.id', '=', 'receipt.purchase_order_line_id')->on('line.tenant_id', '=', 'receipt.tenant_id');
        })->where('receipt.tenant_id', $tenantId)->whereIn('line.code', $lineCodes)->select('line.code')
            ->selectRaw('SUM(receipt.received_quantity) AS quantity')->groupBy('line.code')->get()->keyBy('code');
    }

    public function ownershipItemReceipts(int $tenantId, array $ownedIds): \Illuminate\Support\Collection
    {
        // Aggregate canonical source receipts in SQL; an item page never loads every lot source into PHP.
        return DB::table('acquisition_lots as lot')->join('purchase_order_lines as line', function ($join): void {
            $join->on('line.code', '=', 'lot.source_code')->on('line.tenant_id', '=', 'lot.tenant_id');
        })->join('purchase_receipt_lines as receipt', function ($join): void {
            $join->on('receipt.purchase_order_line_id', '=', 'line.id')->on('receipt.tenant_id', '=', 'line.tenant_id');
        })->where('lot.tenant_id', $tenantId)->where('lot.source_module', 'PURCHASING')->where('lot.source_type', 'PurchaseOrderLine')
            ->whereIn('lot.owned_item_id', $ownedIds)->select('lot.owned_item_id')->selectRaw('SUM(receipt.received_quantity) AS quantity')
            ->groupBy('lot.owned_item_id')->get()->keyBy('owned_item_id');
    }

    public function suppliers(int $tenantId, ?string $search): Collection
    {
        return PurchaseSupplier::query()->where('tenant_id', $tenantId)
            ->when($search, fn ($q) => $q->where(fn ($s) => $s->where('code', 'like', "%{$search}%")
                ->orWhereHas('person', fn ($person) => $person->where('name', 'like', "%{$search}%"))))
            ->with('person.customer', 'person.lender')->orderBy('id')->limit(100)->get();
    }

    public function supplierByCode(int $tenantId, string $code, bool $lock = false): ?PurchaseSupplier
    {
        $query = PurchaseSupplier::query()->where('tenant_id', $tenantId)->where('code', $code);
        return ($lock ? $query->lockForUpdate() : $query)->with('person.customer', 'person.lender')->first();
    }

    public function supplier(int $tenantId, string $code): ?PurchaseSupplier
    {
        return PurchaseSupplier::query()->where('tenant_id', $tenantId)->where('code', $code)
            ->with('person.customer', 'person.lender')->first();
    }

    public function createSupplier(int $tenantId, array $data): PurchaseSupplier
    {
        return PurchaseSupplier::query()->create(['tenant_id' => $tenantId] + $data);
    }

    public function personForCustomerCode(int $tenantId, string $code): ?TenantPerson
    {
        return TenantPerson::query()->where('tenant_id', $tenantId)
            ->whereHas('customer', fn ($q) => $q->where('code', $code))->first();
    }

    public function personForLenderCode(int $tenantId, string $code): ?TenantPerson
    {
        return TenantPerson::query()->where('tenant_id', $tenantId)
            ->whereHas('lender', fn ($q) => $q->where('code', $code))->first();
    }

    public function supplierForPerson(int $tenantId, int $personId): ?PurchaseSupplier
    {
        return PurchaseSupplier::query()->where('tenant_id', $tenantId)->where('person_id', $personId)->first();
    }

    public function createPerson(int $tenantId, array $data): TenantPerson
    {
        return TenantPerson::query()->create(['tenant_id' => $tenantId] + $data);
    }

    public function updatePerson(TenantPerson $person, array $data): TenantPerson
    {
        $person->update($data);
        return $person->refresh();
    }

    public function updateSupplier(PurchaseSupplier $supplier, array $data): PurchaseSupplier
    {
        $supplier->update($data);
        return $supplier->refresh();
    }

    public function orders(int $tenantId, ?string $search): Collection
    {
        return PurchaseOrder::query()->where('tenant_id', $tenantId)
            ->when($search, fn ($q) => $q->where(fn ($s) => $s->where('code', 'like', "%{$search}%")
                ->orWhereHas('supplier.person', fn ($person) => $person->where('name', 'like', "%{$search}%"))))
            ->with(['supplier.person.customer', 'supplier.person.lender', 'lines.catalogItem', 'supplierPayables'])->orderByDesc('id')->limit(100)->get();
    }

    public function orderByCode(int $tenantId, string $code, bool $lock = false): ?PurchaseOrder
    {
        $query = PurchaseOrder::query()->where('tenant_id', $tenantId)->where('code', $code);
        if ($lock) $query->lockForUpdate();
        return $query->with(['supplier.person.customer', 'supplier.person.lender', 'lines.catalogItem', 'supplierPayables'])->first();
    }

    // Lock the supplier before checking cumulative order receipt and return values.
    public function lockSupplier(int $tenantId, int $supplierId): void
    {
        PurchaseSupplier::query()->where('tenant_id', $tenantId)->whereKey($supplierId)->lockForUpdate()->firstOrFail();
    }

    public function createOrder(int $tenantId, array $data): PurchaseOrder
    {
        return PurchaseOrder::query()->create(['tenant_id' => $tenantId] + $data);
    }

    public function updateOrder(PurchaseOrder $order, array $data): PurchaseOrder
    {
        $order->update($data);
        return $order->refresh()->load(['supplier.person.customer', 'supplier.person.lender', 'lines.catalogItem']);
    }

    public function deleteOrderLines(int $tenantId, int $orderId): void
    {
        PurchaseOrderLine::query()
            ->where('tenant_id', $tenantId)
            ->where('purchase_order_id', $orderId)
            ->delete();
    }
    public function createOrderLine(int $tenantId, int $orderId, array $data): PurchaseOrderLine
    {
        return PurchaseOrderLine::query()->create(['tenant_id' => $tenantId, 'purchase_order_id' => $orderId] + $data);
    }

    // Persist the stable Inventory identity after a non-unique purchase order item is first received.
    public function updateOrderLine(PurchaseOrderLine $line, array $data): PurchaseOrderLine
    {
        $line->update($data);
        return $line->refresh()->load(['catalogItem', 'inventoryItem']);
    }

    public function lineByCodeForOrder(int $tenantId, int $orderId, string $code, bool $lock = false): ?PurchaseOrderLine
    {
        $query = PurchaseOrderLine::query()->where('tenant_id', $tenantId)->where('purchase_order_id', $orderId)->where('code', $code);
        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    public function receivedForOrderLine(int $tenantId, int $lineId): float
    {
        return (float) PurchaseReceiptLine::query()->where('tenant_id', $tenantId)->where('purchase_order_line_id', $lineId)->sum('received_quantity');
    }

    public function returnedForReceiptLine(int $tenantId, int $receiptLineId): float
    {
        return (float) PurchaseReturnLine::query()->where('tenant_id', $tenantId)->where('purchase_receipt_line_id', $receiptLineId)->sum('returned_quantity');
    }

    public function orderReceived(int $tenantId, int $orderId): float
    {
        return (float) PurchaseReceiptLine::query()->where('tenant_id', $tenantId)
            ->whereHas('orderLine', fn ($q) => $q->where('purchase_order_id', $orderId))->sum('received_quantity');
    }

    // List serialized Inventory unit codes already returned from the same receipt item.
    public function returnedUnitCodes(int $tenantId, int $receiptItemId): array
    {
        return PurchaseReturnLine::query()->where('tenant_id', $tenantId)->where('purchase_receipt_line_id', $receiptItemId)
            ->get(['serialized_item_codes'])->flatMap(fn (PurchaseReturnLine $item) => $item->serialized_item_codes ?? [])->all();
    }
    public function orderReturned(int $tenantId, int $orderId): float
    {
        return (float) PurchaseReturnLine::query()->where('tenant_id', $tenantId)
            ->whereHas('receiptLine.orderLine', fn ($q) => $q->where('purchase_order_id', $orderId))->sum('returned_quantity');
    }

    public function paymentsForOrder(int $tenantId, int $orderId): Collection
    {
        return PurchasePayment::query()->where('tenant_id', $tenantId)->where('purchase_order_id', $orderId)->with('refunds')->orderBy('paid_at')->get();
    }

    public function paymentByCode(int $tenantId, string $code, bool $lock = false): ?PurchasePayment
    {
        $query = PurchasePayment::query()->where('tenant_id', $tenantId)->where('code', $code);
        if ($lock) $query->lockForUpdate();
        return $query->with(['refunds', 'order.lines'])->first();
    }

    public function createPayment(int $tenantId, array $data): PurchasePayment
    {
        return PurchasePayment::query()->create(['tenant_id' => $tenantId] + $data);
    }

    // Persist the accounting links created for a purchase payment.
    public function updatePaymentAccounting(PurchasePayment $payment, int $accountId, int $accountingId): PurchasePayment
    {
        $payment->update(['financial_account_id' => $accountId, 'accounting_transaction_id' => $accountingId]);
        return $payment->refresh();
    }

    public function createRefund(int $tenantId, int $paymentId, array $data): PurchaseRefund
    {
        return PurchaseRefund::query()->create(['tenant_id' => $tenantId, 'purchase_payment_id' => $paymentId] + $data);
    }

    // Persist the accounting links created for a supplier refund.
    public function updateRefundAccounting(PurchaseRefund $refund, int $accountId, int $accountingId): PurchaseRefund
    {
        $refund->update(['financial_account_id' => $accountId, 'accounting_transaction_id' => $accountingId]);
        return $refund->refresh();
    }

    public function createReceipt(int $tenantId, array $data): PurchaseReceipt
    {
        return PurchaseReceipt::query()->create(['tenant_id' => $tenantId] + $data);
    }

    // Persist the Supplier Payable reference and applied supplier credit on its receipt.
    public function updateReceipt(PurchaseReceipt $receipt, array $data): PurchaseReceipt
    {
        $receipt->update($data);
        return $receipt->refresh()->load(['lines.orderLine']);
    }
    public function createReceiptLine(int $tenantId, int $receiptId, int $orderLineId, float $quantity, string $code, array $integrationData = []): PurchaseReceiptLine
    {
        return PurchaseReceiptLine::query()->create([
            'tenant_id' => $tenantId, 'code' => $code, 'purchase_receipt_id' => $receiptId,
            'purchase_order_line_id' => $orderLineId, 'received_quantity' => $quantity,
        ] + $integrationData);
    }

    // Update the receipt item after Inventory and Ownership return their public business references.
    public function updateReceiptLine(PurchaseReceiptLine $receiptLine, array $data): PurchaseReceiptLine
    {
        $receiptLine->update($data);
        return $receiptLine->refresh()->load(['receipt', 'orderLine']);
    }

    public function receiptLineByCode(int $tenantId, string $code, bool $lock = false): ?PurchaseReceiptLine
    {
        $query = PurchaseReceiptLine::query()->where('tenant_id', $tenantId)->where('code', $code);
        if ($lock) $query->lockForUpdate();
        return $query->with(['receipt', 'orderLine'])->first();
    }

    public function createReturn(int $tenantId, array $data): PurchaseReturn
    {
        return PurchaseReturn::query()->create(['tenant_id' => $tenantId] + $data);
    }

    // Persist supplier credit and payable adjustment references on the return item.
    public function updateReturnLine(PurchaseReturnLine $returnItem, array $data): PurchaseReturnLine
    {
        $returnItem->update($data);
        return $returnItem->refresh()->load(['receiptLine.orderLine']);
    }
    public function createReturnLine(int $tenantId, int $returnId, int $receiptLineId, float $quantity, string $code, array $unitCodes = []): PurchaseReturnLine
    {
        return PurchaseReturnLine::query()->create([
            'tenant_id' => $tenantId, 'code' => $code, 'purchase_return_id' => $returnId,
            'purchase_receipt_line_id' => $receiptLineId, 'returned_quantity' => $quantity, 'serialized_item_codes' => $unitCodes,
        ]);
    }

    public function receiptsForOrder(int $tenantId, int $orderId): Collection
    {
        return PurchaseReceipt::query()->where('tenant_id', $tenantId)->where('purchase_order_id', $orderId)
            ->with('lines.orderLine')->orderBy('received_at')->get();
    }

    public function returnsForOrder(int $tenantId, int $orderId): Collection
    {
        return PurchaseReturn::query()->where('tenant_id', $tenantId)->where('purchase_order_id', $orderId)
            ->with('lines.receiptLine.orderLine')->orderBy('returned_at')->get();
    }
}
