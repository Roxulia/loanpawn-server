<?php

namespace App\Repository;

use App\Models\DeliveryModule\Delivery;
use App\Models\DeliveryModule\DeliveryItem;
use App\Models\DeliveryModule\DeliveryOrderCharge;
use App\Models\SalesModule\SaleOrder;
use Illuminate\Database\Eloquent\Collection;

class DeliveryRepository
{
    // List tenant deliveries with their linked orders and fulfillment allocations.
    public function deliveries(int $tenantId): Collection
    {
        return Delivery::query()->where('tenant_id', $tenantId)->with(['salesOrders.customer', 'allocations.line', 'allocations.lot', 'items.salesOrderLine.order'])
            ->orderByDesc('id')->limit(100)->get();
    }

    // Resolve one delivery under the current tenant before it can be completed or viewed.
    public function findByCode(int $tenantId, string $code, bool $lock = false): ?Delivery
    {
        $query = Delivery::query()->where('tenant_id', $tenantId)->where('code', $code);
        if ($lock) $query->lockForUpdate();
        return $query->with(['salesOrders.customer', 'allocations.line', 'allocations.lot', 'items.salesOrderLine.order'])->first();
    }

    public function create(int $tenantId, array $data): Delivery
    {
        return Delivery::query()->create(['tenant_id' => $tenantId] + $data);
    }

    public function findById(int $tenantId, int $id, bool $lock = false): ?Delivery
    {
        $query = Delivery::query()->where('tenant_id', $tenantId)->whereKey($id);
        if ($lock) $query->lockForUpdate();
        return $query->first();
    }

    public function createItem(int $tenantId, string $code, int $deliveryId, int $salesOrderLineId, float $quantity): DeliveryItem
    {
        return DeliveryItem::query()->create([
            'tenant_id' => $tenantId, 'code' => $code, 'delivery_id' => $deliveryId,
            'sales_order_line_id' => $salesOrderLineId, 'planned_quantity' => $quantity,
        ]);
    }

    public function itemsForDelivery(int $tenantId, int $deliveryId, bool $lock = false): Collection
    {
        $query = DeliveryItem::query()->where('tenant_id', $tenantId)->where('delivery_id', $deliveryId)
            ->with(['salesOrderLine.order'])->orderBy('id');
        if ($lock) $query->lockForUpdate();
        return $query->get();
    }

    public function plannedForSalesLine(int $tenantId, int $salesLineId): float
    {
        return (float) DeliveryItem::query()->where('tenant_id', $tenantId)->where('sales_order_line_id', $salesLineId)
            ->whereNull('delivered_quantity')->whereHas('delivery', fn ($query) => $query->whereIn('status', ['PLANNED', 'IN_TRANSIT']))
            ->sum('planned_quantity');
    }

    public function completeItem(DeliveryItem $item, float $quantity, array $unitCodes = []): DeliveryItem
    {
        $item->update(['delivered_quantity' => $quantity, 'inventory_unit_codes' => $unitCodes]);
        return $item->refresh();
    }

    public function updateStatus(Delivery $delivery, string $status, ?string $deliveredAt = null): Delivery
    {
        $delivery->update(['status' => $status, 'delivered_at' => $deliveredAt]);
        return $delivery->refresh();
    }

    // Persist each side of the tenant-scoped many-to-many Delivery/Sales-order association.
    public function attachOrder(int $tenantId, Delivery $delivery, SaleOrder $order): void
    {
        $delivery->salesOrders()->syncWithoutDetaching([
            $order->id => ['tenant_id' => $tenantId, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    // Keep a single delivery charge record owned by the Delivery module for each Sales order.
    public function updateOrderCharge(int $tenantId, int $orderId, string $currencyCode, float $amount): DeliveryOrderCharge
    {
        return DeliveryOrderCharge::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'sales_order_id' => $orderId],
            ['currency_code' => strtoupper($currencyCode), 'amount' => round($amount, 2)],
        );
    }

    public function orderCharge(int $tenantId, int $orderId, bool $lock = false): ?DeliveryOrderCharge
    {
        $query = DeliveryOrderCharge::query()->where('tenant_id', $tenantId)->where('sales_order_id', $orderId);
        if ($lock) $query->lockForUpdate();
        return $query->first();
    }

    // Mark the order fee recognized on the first successful Delivery for the order.
    public function recognizeOrderCharge(int $tenantId, int $orderId, int $deliveryId): float
    {
        $charge = $this->orderCharge($tenantId, $orderId, true);
        if ($charge === null || $charge->recognized_delivery_id !== null) return 0.0;
        $charge->update(['recognized_delivery_id' => $deliveryId]);
        return (float) $charge->amount;
    }

    public function recognizedChargeForOrder(int $tenantId, int $orderId): float
    {
        return (float) DeliveryOrderCharge::query()->where('tenant_id', $tenantId)->where('sales_order_id', $orderId)
            ->whereNotNull('recognized_delivery_id')->sum('amount');
    }

    public function updateCharge(DeliveryOrderCharge $charge, int $deliveryId): void
    {
        $charge->update(['recognized_delivery_id' => $deliveryId]);
    }
}
