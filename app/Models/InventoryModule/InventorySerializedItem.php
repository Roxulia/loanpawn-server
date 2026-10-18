<?php

namespace App\Models\InventoryModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents one individually identified physical item in SERIALIZED inventory.
 * This is distinct from a measurement unit such as kilograms or pieces.
 * Uses the conventional inventory_serialized_items table for its physical identity.
 */
class InventorySerializedItem extends Model
{
    use BelongToTenant;

    // Store identity attributes; measurement units belong to the parent Inventory item.
    protected $fillable = [
        'tenant_id',
        'code',
        'inventory_item_id',
        'identifier'
        ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
