<?php

namespace App\Models\PurchasingModule;

use App\Traits\BelongToTenant;
use App\Models\InventoryModule\InventoryItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReceiptLine extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'code', 'purchase_receipt_id', 'purchase_order_line_id', 'received_quantity', 'inventory_location_code', 'inventory_item_id', 'inventory_unit_codes', 'owned_item_code', 'acquisition_lot_code', 'received_value'];
    protected $casts = ['received_quantity' => 'decimal:3', 'received_value' => 'decimal:2', 'inventory_unit_codes' => 'array'];
    public function receipt(): BelongsTo { return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id'); }
    public function orderLine(): BelongsTo { return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id'); }
    // Resolve the Inventory item referenced by this received purchase order item quantity.
    public function inventoryItem(): BelongsTo { return $this->belongsTo(InventoryItem::class); }
}
