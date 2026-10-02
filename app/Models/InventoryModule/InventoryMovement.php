<?php

namespace App\Models\InventoryModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    use BelongToTenant;

    protected $fillable = [
        'tenant_id', 'code', 'inventory_item_id', 'inventory_unit_id', 'from_location_id', 'to_location_id',
        'quantity', 'movement_type', 'reason', 'source_type', 'source_code', 'idempotency_record_id',
        'created_by', 'occurred_at',
    ];

    protected $casts = ['quantity' => 'decimal:3', 'occurred_at' => 'immutable_datetime'];

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'to_location_id');
    }
}
