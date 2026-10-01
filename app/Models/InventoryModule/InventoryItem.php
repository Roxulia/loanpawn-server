<?php

namespace App\Models\InventoryModule;

use App\Traits\BelongToTenant;
use App\Models\CatalogModule\CatalogItem;
use App\Models\CatalogModule\CatalogUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryItem extends Model
{
    use BelongToTenant;

    protected $fillable = [
        'tenant_id', 'catalog_item_id', 'unit_id', 'tracking_mode', 'name', 'description', 'quantity_scale',
    ];

    protected $casts = ['quantity_scale' => 'decimal:3'];

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(CatalogUnit::class);
    }
}
