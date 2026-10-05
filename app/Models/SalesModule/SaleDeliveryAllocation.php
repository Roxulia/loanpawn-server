<?php

namespace App\Models\SalesModule;

use App\Models\OwnershipModule\AcquisitionLot;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleDeliveryAllocation extends Model
{
    use BelongToTenant;
    protected $fillable = ['tenant_id', 'code', 'sales_delivery_id', 'delivery_id', 'sales_order_line_id', 'acquisition_lot_id', 'inventory_location_id', 'quantity', 'unit_cost', 'unit_price', 'inventory_unit_codes', 'cogs_expense_transaction_id', 'inventory_asset_transaction_id'];
    protected $casts = ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:4', 'unit_price' => 'decimal:2', 'inventory_unit_codes' => 'array'];
    public function line(): BelongsTo { return $this->belongsTo(SaleOrderLine::class, 'sales_order_line_id'); }
    public function lot(): BelongsTo { return $this->belongsTo(AcquisitionLot::class, 'acquisition_lot_id'); }
    public function delivery(): BelongsTo { return $this->belongsTo(SaleDelivery::class, 'sales_delivery_id'); }
    public function fulfillmentDelivery(): BelongsTo { return $this->belongsTo(\App\Models\DeliveryModule\Delivery::class, 'delivery_id'); }
}
