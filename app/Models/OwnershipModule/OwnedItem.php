<?php

namespace App\Models\OwnershipModule;

use App\Models\CatalogModule\CatalogItem;
use App\Models\InventoryModule\InventoryItem;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents the tenant's ownership record for one Inventory item.
 * Gives acquisition lots and Ownership movements a shared parent while Inventory
 * separately records where the physical goods are held.
 * Its update_key supports stale-write detection by later mutation services.
 * The record remains after ownership is exhausted so earlier lots and history stay linked.
 */
class OwnedItem extends Model
{
    use BelongToTenant;

    // The service advances this version once per logical mutation; summaries leave it unchanged.
    protected $fillable = ['tenant_id', 'code', 'inventory_item_id', 'catalog_item_id', 'lifecycle_status', 'created_by', 'update_key'];

    protected $casts = ['update_key' => 'integer'];

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function acquisitionLots(): HasMany
    {
        return $this->hasMany(AcquisitionLot::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(OwnershipMovement::class);
    }
}
