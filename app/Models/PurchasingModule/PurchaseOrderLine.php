<?php

namespace App\Models\PurchasingModule;

use App\Traits\BelongToTenant;
use App\Models\CatalogModule\CatalogItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'code', 'purchase_order_id', 'catalog_item_id', 'item_description', 'tracking_mode', 'unit_label', 'ordered_quantity', 'unit_price'];
    protected $casts = ['ordered_quantity' => 'decimal:3', 'unit_price' => 'decimal:2'];
    public function order(): BelongsTo { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
    public function catalogItem(): BelongsTo { return $this->belongsTo(CatalogItem::class); }
}
