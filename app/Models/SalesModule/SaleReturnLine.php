<?php

namespace App\Models\SalesModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReturnLine extends Model
{
    use BelongToTenant;
    // Eloquent's inferred sale_return_lines name does not match the existing Sales schema.
    protected $table = 'sales_return_lines';
    protected $fillable = ['tenant_id', 'code', 'sales_return_id', 'sales_delivery_allocation_id', 'inventory_location_id', 'quantity', 'cash_refund_amount', 'receivable_credit_amount', 'serialized_item_codes', 'disposition', 'inventory_movement_id', 'cogs_reversal_transaction_id', 'inventory_asset_return_transaction_id', 'inventory_writeoff_transaction_id'];
    protected $casts = ['quantity' => 'decimal:3', 'cash_refund_amount' => 'decimal:2'];
    public function allocation(): BelongsTo { return $this->belongsTo(SaleDeliveryAllocation::class, 'sales_delivery_allocation_id'); }
    // Preserve the parent order as part of internal source authority checks.
    public function returnRecord(): BelongsTo { return $this->belongsTo(SaleReturn::class, 'sales_return_id'); }
}
