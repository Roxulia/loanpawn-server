<?php

namespace App\Models\OwnershipModule;

use App\Models\CatalogModule\CatalogItem;
use App\Models\InventoryModule\InventoryItem;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OwnedItem extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'code', 'inventory_item_id', 'catalog_item_id', 'lifecycle_status', 'created_by'];

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
