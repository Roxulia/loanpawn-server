<?php

namespace App\Repository;

use App\Models\SalesModule\SaleReceivable;
use App\Models\SalesModule\SaleReceivablePayment;
use App\Models\SalesModule\SaleReceivableAdjustment;
use Illuminate\Database\Eloquent\Collection;

class SaleReceivableRepository
{
    // List this tenant's sale receivables with party, source, and currency details.
    public function list(int $tenantId): Collection
    {
        return SaleReceivable::query()->where('tenant_id', $tenantId)
            ->with(['customer', 'order', 'delivery', 'currency', 'payments'])
            ->orderByDesc('id')->limit(200)->get();
    }

    // Lock a tenant receivable by its public code before changing its balance.
    public function findByCode(int $tenantId, string $code, bool $lock = false): ?SaleReceivable
    {
        $query = SaleReceivable::query()->where('tenant_id', $tenantId)->where('code', $code);
        if ($lock) $query->lockForUpdate();
        return $query->with(['customer', 'order', 'delivery', 'currency', 'payments'])->first();
    }

    // Retrieve a delivery's receivable while preventing duplicate creation.
    public function findByDelivery(int $tenantId, int $deliveryId, bool $lock = false): ?SaleReceivable
    {
        $query = SaleReceivable::query()->where('tenant_id', $tenantId)->where('delivery_id', $deliveryId);
        if ($lock) $query->lockForUpdate();
        return $query->first();
    }

    // Return order receivables in oldest delivery order for deterministic allocation.
    public function forOrder(int $tenantId, int $orderId, bool $lock = false, ?string $code = null): Collection
    {
        $query = SaleReceivable::query()->where('tenant_id', $tenantId)->where('sales_order_id', $orderId)
            ->where('balance_amount', '>', 0)->when($code, fn ($builder) => $builder->where('code', $code))->orderBy('id');
        if ($lock) $query->lockForUpdate();
        return $query->with('currency')->get();
    }

    // Return all receivables for order history, including settled balances.
    public function historyForOrder(int $tenantId, int $orderId): Collection
    {
        return SaleReceivable::query()->where('tenant_id', $tenantId)->where('sales_order_id', $orderId)
            ->with(['customer', 'order', 'delivery', 'currency', 'payments'])->orderBy('id')->get();
    }

    // Sum receivables created against all deliveries of an order.
    public function originalForOrder(int $tenantId, int $orderId): float
    {
        return (float) SaleReceivable::query()->where('tenant_id', $tenantId)->where('sales_order_id', $orderId)->sum('original_amount');
    }

    // Sum the remaining sale balance across all deliveries of an order.
    public function balanceForOrder(int $tenantId, int $orderId): float
    {
        return (float) SaleReceivable::query()->where('tenant_id', $tenantId)->where('sales_order_id', $orderId)->sum('balance_amount');
    }

    // Sum customer collections already applied to this sale's receivables.
    public function paidForOrder(int $tenantId, int $orderId): float
    {
        return (float) SaleReceivablePayment::query()->where('tenant_id', $tenantId)
            ->whereHas('receivable', fn ($query) => $query->where('sales_order_id', $orderId))->sum('amount');
    }

    // Create one interest-free, delivery-linked balance record.
    public function create(int $tenantId, array $data): SaleReceivable
    {
        return SaleReceivable::query()->create(['tenant_id' => $tenantId] + $data);
    }

    // Create a collection entry against one delivery receivable.
    public function createPayment(int $tenantId, array $data): SaleReceivablePayment
    {
        return SaleReceivablePayment::query()->create(['tenant_id' => $tenantId] + $data);
    }

    public function createAdjustment(int $tenantId, int $receivableId, array $data): SaleReceivableAdjustment
    {
        return SaleReceivableAdjustment::query()->create(['tenant_id' => $tenantId, 'sale_receivable_id' => $receivableId] + $data);
    }

    public function updateAdjustmentAccounting(SaleReceivableAdjustment $adjustment, int $accountingId): SaleReceivableAdjustment
    {
        $adjustment->update(['accounting_transaction_id' => $accountingId]);
        return $adjustment->refresh();
    }

    // Update the remaining balance and lifecycle status consistently.
    public function updateBalance(SaleReceivable $receivable, float $amount): SaleReceivable
    {
        $balance = round(max(0, $amount), 2);
        $status = $balance <= 0 ? 'SETTLED' : ($balance + 0.001 < (float) $receivable->original_amount ? 'PARTIAL' : 'OPEN');
        $receivable->update(['balance_amount' => $balance, 'status' => $status]);
        return $receivable->refresh();
    }

    // Attach accounting history to the collection record.
    public function updatePaymentAccounting(SaleReceivablePayment $payment, int $accountingId): SaleReceivablePayment
    {
        $payment->update(['accounting_transaction_id' => $accountingId]);
        return $payment->refresh();
    }
}
