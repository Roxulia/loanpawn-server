<?php

namespace App\Repository;

use Illuminate\Support\Facades\DB;

class SalesReconciliationRepository
{
    // Compare each delivered allocation with its physical, ownership, and COGS postings.
    public function deliveryIssues(int $tenantId): array
    {
        return DB::table('sales_delivery_allocations as allocation')
            ->join('sales_order_lines as line', 'line.id', '=', 'allocation.sales_order_line_id')
            ->leftJoin('inventory_movements as inventory', function ($join): void {
                $join->on('inventory.tenant_id', '=', 'allocation.tenant_id')
                    ->on('inventory.source_code', '=', 'allocation.code')
                    ->where('inventory.source_type', '=', 'SaleDeliveryAllocation');
            })
            ->leftJoin('ownership_movements as ownership', function ($join): void {
                $join->on('ownership.tenant_id', '=', 'allocation.tenant_id')
                    ->on('ownership.source_code', '=', 'allocation.code')
                    ->where('ownership.source_type', '=', 'SaleLine');
            })
            ->where('allocation.tenant_id', $tenantId)
            ->where(function ($query): void {
                $query->whereNull('inventory.id')->orWhereNull('ownership.id')
                    ->orWhereNull('allocation.cogs_expense_transaction_id')->orWhereNull('allocation.inventory_asset_transaction_id');
            })
            ->select('allocation.code as allocation_code', 'line.sales_order_id', 'inventory.id as inventory_movement_id',
                'ownership.id as ownership_movement_id', 'allocation.cogs_expense_transaction_id', 'allocation.inventory_asset_transaction_id')
            ->get()->map(fn ($row): array => [
                'record_type' => 'DELIVERY_ALLOCATION', 'code' => $row->allocation_code,
                'missing' => array_values(array_filter([
                    $row->inventory_movement_id === null ? 'INVENTORY_MOVEMENT' : null,
                    $row->ownership_movement_id === null ? 'OWNERSHIP_MOVEMENT' : null,
                    $row->cogs_expense_transaction_id === null ? 'COGS_EXPENSE' : null,
                    $row->inventory_asset_transaction_id === null ? 'INVENTORY_ASSET_POSTING' : null,
                ])),
            ])->all();
    }

    // Find return lines missing their physical, ownership, or accounting follow-up.
    public function returnIssues(int $tenantId): array
    {
        return DB::table('sales_return_lines as return_line')
            ->leftJoin('inventory_movements as inventory', function ($join): void {
                $join->on('inventory.tenant_id', '=', 'return_line.tenant_id')
                    ->on('inventory.source_code', '=', 'return_line.code')->where('inventory.source_type', '=', 'SaleReturnLine');
            })
            ->leftJoin('ownership_movements as ownership', function ($join): void {
                $join->on('ownership.tenant_id', '=', 'return_line.tenant_id')
                    ->on('ownership.source_code', '=', 'return_line.code')->where('ownership.source_type', '=', 'SaleReturnLine');
            })
            ->where('return_line.tenant_id', $tenantId)
            ->where(function ($query): void {
                $query->whereNull('inventory.id')->orWhereNull('ownership.id')->orWhereNull('return_line.cogs_reversal_transaction_id');
            })
            ->select('return_line.code', 'inventory.id as inventory_movement_id', 'ownership.id as ownership_movement_id', 'return_line.cogs_reversal_transaction_id')
            ->get()->map(fn ($row): array => [
                'record_type' => 'SALE_RETURN', 'code' => $row->code,
                'missing' => array_values(array_filter([
                    $row->inventory_movement_id === null ? 'INVENTORY_MOVEMENT' : null,
                    $row->ownership_movement_id === null ? 'OWNERSHIP_MOVEMENT' : null,
                    $row->cogs_reversal_transaction_id === null ? 'COGS_REVERSAL' : null,
                ])),
            ])->all();
    }

    // Report payment and refund rows whose accounting references were never recorded.
    public function financialIssues(int $tenantId): array
    {
        $payments = DB::table('sales_payments')->where('tenant_id', $tenantId)->whereNull('accounting_transaction_id')
            ->get(['code'])->map(fn ($row): array => ['record_type' => 'SALE_PAYMENT', 'code' => $row->code, 'missing' => ['ACCOUNTING']]);
        $refunds = DB::table('sales_payment_refunds')->where('tenant_id', $tenantId)->whereNull('accounting_transaction_id')
            ->get(['code'])->map(fn ($row): array => ['record_type' => 'SALE_REFUND', 'code' => $row->code, 'missing' => ['ACCOUNTING']]);
        $collections = DB::table('sale_receivable_payments')->where('tenant_id', $tenantId)->whereNull('accounting_transaction_id')
            ->get(['code'])->map(fn ($row): array => ['record_type' => 'SALE_RECEIVABLE_PAYMENT', 'code' => $row->code, 'missing' => ['ACCOUNTING']]);
        return $payments->concat($refunds)->concat($collections)->values()->all();
    }
}
