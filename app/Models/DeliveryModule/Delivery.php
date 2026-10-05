<?php

namespace App\Models\DeliveryModule;

use App\Models\SalesModule\SaleOrder;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Delivery extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'code', 'status', 'scheduled_at', 'delivered_at', 'recipient_name', 'address', 'reference', 'note', 'created_by'];
    protected $casts = ['scheduled_at' => 'date:Y-m-d', 'delivered_at' => 'date:Y-m-d'];

    // Allow one delivery to fulfill several sales and each sale to be fulfilled by several deliveries.
    public function salesOrders(): BelongsToMany
    {
        return $this->belongsToMany(SaleOrder::class, 'delivery_sales_orders', 'delivery_id', 'sales_order_id')->withPivot('tenant_id')->withTimestamps();
    }

    // Expose the allocated sale lines and FIFO lot snapshots carried by this delivery.
    public function allocations(): HasMany
    {
        return $this->hasMany(\App\Models\SalesModule\SaleDeliveryAllocation::class, 'delivery_id');
    }

    public function items(): HasMany { return $this->hasMany(DeliveryItem::class); }
}
