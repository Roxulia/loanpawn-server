<?php

namespace App\Models\SalesModule;

use App\Models\InventoryModule\InventoryItem;
use App\Models\OwnershipModule\OwnedItem;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleOrderLine extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'code', 'sales_order_id', 'inventory_item_id', 'owned_item_id', 'item_description', 'tracking_mode', 'unit_label', 'ordered_quantity', 'unit_price'];
    protected $casts = ['ordered_quantity' => 'decimal:3', 'unit_price' => 'decimal:2'];

    public function order(): BelongsTo { return $this->belongsTo(SaleOrder::class, 'sales_order_id'); }
    public function inventoryItem(): BelongsTo { return $this->belongsTo(InventoryItem::class); }
    public function ownedItem(): BelongsTo { return $this->belongsTo(OwnedItem::class); }
    public function allocations(): HasMany { return $this->hasMany(SaleDeliveryAllocation::class, 'sales_order_line_id'); }
}
