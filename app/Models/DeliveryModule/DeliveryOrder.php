<?php

namespace App\Models\DeliveryModule;

use App\Models\SalesModule\SaleOrder;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Relations\Pivot;

class DeliveryOrder extends Pivot
{
    use BelongToTenant;

    protected $table = 'delivery_sales_orders';
    protected $fillable = ['tenant_id', 'delivery_id', 'sales_order_id'];
}
