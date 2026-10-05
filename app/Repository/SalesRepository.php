<?php

namespace App\Repository;

use App\Models\SalesModule\SaleDelivery;
use App\Models\SalesModule\SaleDeliveryAllocation;
use App\Models\SalesModule\SaleOrder;
use App\Models\SalesModule\SaleOrderLine;
use App\Models\SalesModule\SaleOrderReservation;
use App\Models\SalesModule\SalePayment;
use App\Models\SalesModule\SalePaymentRefund;
use App\Models\SalesModule\SaleReturn;
use App\Models\SalesModule\SaleReturnLine;
use App\Models\CoreModule\TenantCustomer;
use Illuminate\Database\Eloquent\Collection;

class SalesRepository
{
    public function customerByCode(int $tenantId, string $code): ?TenantCustomer
    {
        return TenantCustomer::query()->where('tenant_id', $tenantId)->where('code', $code)->where('is_deleted', false)->first();
    }

    public function orders(int $tenantId, ?string $search): Collection
    {
        return SaleOrder::query()->where('tenant_id', $tenantId)
            ->when($search, fn ($query) => $query->where('code', 'like', '%'.$search.'%'))
            ->with(['customer', 'lines.inventoryItem', 'payments.refunds', 'deliveries.allocations.line', 'returns.lines'])
            ->orderByDesc('id')->limit(100)->get();
    }

    public function orderByCode(int $tenantId, string $code, bool $lock = false): ?SaleOrder
    {
        $query = SaleOrder::query()->where('tenant_id', $tenantId)->where('code', $code);
        if ($lock) $query->lockForUpdate();
        return $query->with(['customer', 'lines.inventoryItem', 'payments.refunds', 'deliveries.allocations.line', 'returns.lines'])->first();
    }

    public function createOrder(int $tenantId, array $data): SaleOrder
    {
        return SaleOrder::query()->create(['tenant_id' => $tenantId] + $data);
    }

    public function updateOrder(SaleOrder $order, array $data): SaleOrder
    {
        $order->update($data);
        return $order->refresh();
    }

    public function createLine(int $tenantId, int $orderId, array $data): SaleOrderLine
    {
        return SaleOrderLine::query()->create(['tenant_id' => $tenantId, 'sales_order_id' => $orderId] + $data);
    }

    public function deleteLines(int $tenantId, int $orderId): void
    {
        SaleOrderLine::query()->where('tenant_id', $tenantId)->where('sales_order_id', $orderId)->delete();
    }

    public function createReservation(int $tenantId, int $lineId, array $data): SaleOrderReservation
    {
        return SaleOrderReservation::query()->create(['tenant_id' => $tenantId, 'sales_order_line_id' => $lineId] + $data);
    }

    public function reservationsForLine(int $tenantId, int $lineId, bool $lock = false): Collection
    {
        $query = SaleOrderReservation::query()->where('tenant_id', $tenantId)->where('sales_order_line_id', $lineId)
            ->where('status', 'ACTIVE')->with('lot')->orderBy('id');
        return $lock ? $query->lockForUpdate()->get() : $query->get();
    }

    public function updateReservation(SaleOrderReservation $reservation, array $data): SaleOrderReservation
    {
        $reservation->update($data);
        return $reservation->refresh();
    }

    public function reservedForLine(int $tenantId, int $lineId): float
    {
        return (float) SaleOrderReservation::query()->where('tenant_id', $tenantId)->where('sales_order_line_id', $lineId)
            ->where('status', 'ACTIVE')->sum('remaining_quantity');
    }

    public function deliveredForLine(int $tenantId, int $lineId): float
    {
        return (float) SaleDeliveryAllocation::query()->where('tenant_id', $tenantId)->where('sales_order_line_id', $lineId)->sum('quantity');
    }

    public function createPayment(int $tenantId, array $data): SalePayment
    {
        return SalePayment::query()->create(['tenant_id' => $tenantId] + $data);
    }

    public function paymentsForOrder(int $tenantId, int $orderId): Collection
    {
        return SalePayment::query()->where('tenant_id', $tenantId)->where('sales_order_id', $orderId)
            ->with('refunds')->orderBy('paid_at')->orderBy('id')->get();
    }

    public function createDelivery(int $tenantId, array $data): SaleDelivery
    {
        return SaleDelivery::query()->create(['tenant_id' => $tenantId] + $data);
    }

    public function createDeliveryAllocation(int $tenantId, array $data): SaleDeliveryAllocation
    {
        return SaleDeliveryAllocation::query()->create(['tenant_id' => $tenantId] + $data);
    }

    public function updateAllocationAccounting(SaleDeliveryAllocation $allocation, int $expenseId, int $assetId): SaleDeliveryAllocation
    {
        $allocation->update(['cogs_expense_transaction_id' => $expenseId, 'inventory_asset_transaction_id' => $assetId]);
        return $allocation->refresh();
    }

    public function allocationByCode(int $tenantId, string $code, bool $lock = false): ?SaleDeliveryAllocation
    {
        $query = SaleDeliveryAllocation::query()->where('tenant_id', $tenantId)->where('code', $code);
        if ($lock) $query->lockForUpdate();
        return $query->with(['line.order', 'line.inventoryItem', 'line.ownedItem', 'lot', 'delivery'])->first();
    }

    public function allocationsForOrder(int $tenantId, int $orderId): Collection
    {
        return SaleDeliveryAllocation::query()->where('tenant_id', $tenantId)->whereHas('line', fn ($query) => $query->where('sales_order_id', $orderId))
            ->with(['line', 'lot', 'delivery'])->orderBy('id')->get();
    }

    public function createReturn(int $tenantId, array $data): SaleReturn
    {
        return SaleReturn::query()->create(['tenant_id' => $tenantId] + $data);
    }

    public function createReturnLine(int $tenantId, array $data): SaleReturnLine
    {
        return SaleReturnLine::query()->create(['tenant_id' => $tenantId] + $data);
    }

    public function updateReturnLine(SaleReturnLine $line, array $data): SaleReturnLine
    {
        $line->update($data);
        return $line->refresh();
    }

    public function returnedForAllocation(int $tenantId, int $allocationId): float
    {
        return (float) SaleReturnLine::query()->where('tenant_id', $tenantId)->where('sales_delivery_allocation_id', $allocationId)->sum('quantity');
    }

    public function updatePaymentAccounting(SalePayment $payment, int $ledgerId): SalePayment
    {
        $payment->update(['accounting_transaction_id' => $ledgerId]);
        return $payment->refresh();
    }

    public function updateRefundAccounting(SalePaymentRefund $refund, int $ledgerId): SalePaymentRefund
    {
        $refund->update(['accounting_transaction_id' => $ledgerId]);
        return $refund->refresh();
    }

    public function createPaymentRefund(int $tenantId, int $paymentId, array $data): SalePaymentRefund
    {
        return SalePaymentRefund::query()->create(['tenant_id' => $tenantId, 'sales_payment_id' => $paymentId] + $data);
    }

    public function returnsForOrder(int $tenantId, int $orderId): Collection
    {
        return SaleReturn::query()->where('tenant_id', $tenantId)->where('sales_order_id', $orderId)
            ->with('lines.allocation.line')->orderBy('id')->get();
    }

    public function returnedUnitCodesForAllocation(int $tenantId, int $allocationId): array
    {
        return SaleReturnLine::query()->where('tenant_id', $tenantId)->where('sales_delivery_allocation_id', $allocationId)
            ->pluck('inventory_unit_codes')->filter()->flatMap(fn ($codes) => json_decode((string) $codes, true) ?: [])->all();
    }

    public function allocationsForTenant(int $tenantId): Collection
    {
        return SaleDeliveryAllocation::query()->where('tenant_id', $tenantId)
            ->with(['line.order', 'delivery', 'lot'])->orderBy('id')->get();
    }

    public function paymentsForTenant(int $tenantId): Collection
    {
        return SalePayment::query()->where('tenant_id', $tenantId)->with('refunds')->orderBy('id')->get();
    }
}
