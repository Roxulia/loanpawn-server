<?php

namespace App\Models\SalesModule;

use App\Models\CoreModule\TenantCustomer;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleOrder extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'code', 'customer_id', 'status', 'sold_at', 'currency_code', 'note', 'created_by'];
    protected $casts = ['sold_at' => 'date:Y-m-d'];

    public function customer(): BelongsTo { return $this->belongsTo(TenantCustomer::class); }
    public function lines(): HasMany { return $this->hasMany(SaleOrderLine::class, 'sales_order_id'); }
    public function payments(): HasMany { return $this->hasMany(SalePayment::class, 'sales_order_id'); }
    public function deliveries(): HasMany { return $this->hasMany(SaleDelivery::class, 'sales_order_id'); }
    public function returns(): HasMany { return $this->hasMany(SaleReturn::class, 'sales_order_id'); }
}
