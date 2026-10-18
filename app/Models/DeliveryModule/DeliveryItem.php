<?php

namespace App\Models\DeliveryModule;

use App\Models\SalesModule\SaleOrderLine;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryItem extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'code', 'delivery_id', 'sales_order_line_id', 'planned_quantity', 'delivered_quantity', 'serialized_item_codes'];
    protected $casts = ['planned_quantity' => 'decimal:3', 'delivered_quantity' => 'decimal:3', 'serialized_item_codes' => 'array'];

    public function delivery(): BelongsTo { return $this->belongsTo(Delivery::class); }
    public function salesOrderLine(): BelongsTo { return $this->belongsTo(SaleOrderLine::class, 'sales_order_line_id'); }
}
