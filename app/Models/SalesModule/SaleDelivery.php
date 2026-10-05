<?php

namespace App\Models\SalesModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleDelivery extends Model
{
    use BelongToTenant;
    protected $fillable = ['tenant_id', 'code', 'sales_order_id', 'delivered_at', 'note', 'created_by'];
    protected $casts = ['delivered_at' => 'date:Y-m-d'];
    public function order(): BelongsTo { return $this->belongsTo(SaleOrder::class, 'sales_order_id'); }
    public function allocations(): HasMany { return $this->hasMany(SaleDeliveryAllocation::class, 'sales_delivery_id'); }
}
