<?php

namespace App\Models\DeliveryModule;

use App\Models\SalesModule\SaleOrder;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryOrderCharge extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'sales_order_id', 'amount', 'currency_code', 'recognized_delivery_id'];
    protected $casts = ['amount' => 'decimal:2'];

    public function salesOrder(): BelongsTo { return $this->belongsTo(SaleOrder::class); }
    public function recognizedDelivery(): BelongsTo { return $this->belongsTo(Delivery::class); }
}
