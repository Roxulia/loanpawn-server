<?php

namespace App\Models\SalesModule;

use App\Models\OwnershipModule\AcquisitionLot;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleOrderReservation extends Model
{
    use BelongToTenant;
    protected $fillable = ['tenant_id', 'code', 'sales_order_line_id', 'acquisition_lot_id', 'inventory_location_id', 'inventory_location_code', 'inventory_reservation_code', 'quantity', 'remaining_quantity', 'inventory_unit_codes', 'status'];
    protected $casts = ['quantity' => 'decimal:3', 'remaining_quantity' => 'decimal:3', 'inventory_unit_codes' => 'array'];
    public function line(): BelongsTo { return $this->belongsTo(SaleOrderLine::class, 'sales_order_line_id'); }
    public function lot(): BelongsTo { return $this->belongsTo(AcquisitionLot::class, 'acquisition_lot_id'); }
}
