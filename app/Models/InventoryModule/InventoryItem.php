<?php

namespace App\Models\InventoryModule;

use App\Traits\BelongToTenant;
use App\Models\CatalogModule\CatalogItem;
use App\Models\CatalogModule\CatalogUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a tenant's inventory item and its Catalog and measurement-unit links.
 * The integer update_key supports optimistic concurrency checks on stock operations.
 * Physical stock and reservation mutations must increment this version within
 * their owning transaction when the mutation helpers are connected.
 */
class InventoryItem extends Model
{
    use BelongToTenant;

    // Allow repository writes to persist the item's concurrency version.
    protected $fillable = [
        'tenant_id',
        'code',
        'catalog_item_id',
        'unit_id',
        'tracking_mode',
        'name',
        'description',
        'quantity_scale',
        'update_key'
        ];

    // Preserve the existing decimal scale and expose the version as an integer.
    protected $casts = [
        'quantity_scale' => 'decimal:3',
        'update_key' => 'integer'
        ];

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(CatalogUnit::class);
    }
}
