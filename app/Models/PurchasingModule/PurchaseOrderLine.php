<?php

namespace App\Models\PurchasingModule;

use App\Traits\BelongToTenant;
use App\Models\CatalogModule\CatalogItem;
use App\Models\InventoryModule\InventoryItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'code', 'purchase_order_id', 'catalog_item_id', 'inventory_item_id', 'item_description', 'tracking_mode', 'unit_label', 'unit_code', 'ordered_quantity', 'unit_price'];
    protected $casts = ['ordered_quantity' => 'decimal:3', 'unit_price' => 'decimal:2'];
    public function order(): BelongsTo { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
    public function catalogItem(): BelongsTo { return $this->belongsTo(CatalogItem::class); }
    // Resolve the stable Inventory item assigned when this purchase order item is first received.
    public function inventoryItem(): BelongsTo { return $this->belongsTo(InventoryItem::class); }
}
