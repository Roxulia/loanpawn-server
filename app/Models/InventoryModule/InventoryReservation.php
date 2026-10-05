<?php

namespace App\Models\InventoryModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;

class InventoryReservation extends Model
{
    use BelongToTenant;
    protected $fillable = ['tenant_id', 'code', 'inventory_item_id', 'inventory_location_id', 'source_type', 'source_code', 'quantity', 'remaining_quantity', 'inventory_unit_codes', 'status'];
    protected $casts = ['quantity' => 'decimal:3', 'remaining_quantity' => 'decimal:3', 'inventory_unit_codes' => 'array'];
}
