<?php

namespace App\Models\SalesModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReturnLine extends Model
{
    use BelongToTenant;
    protected $fillable = ['tenant_id', 'code', 'sales_return_id', 'sales_delivery_allocation_id', 'inventory_location_id', 'quantity', 'cash_refund_amount', 'inventory_unit_codes'];
    protected $casts = ['quantity' => 'decimal:3', 'cash_refund_amount' => 'decimal:2'];
    public function allocation(): BelongsTo { return $this->belongsTo(SaleDeliveryAllocation::class, 'sales_delivery_allocation_id'); }
}
