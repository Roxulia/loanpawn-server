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
            ->get(['inventory_unit_codes'])->flatMap(fn (PurchaseReturnLine $item) => $item->inventory_unit_codes ?? [])->all();
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
            'purchase_receipt_line_id' => $receiptLineId, 'returned_quantity' => $quantity, 'inventory_unit_codes' => $unitCodes,
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
