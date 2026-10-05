<?php

namespace App\Models\SalesModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleReturn extends Model
{
    use BelongToTenant;
    protected $fillable = ['tenant_id', 'code', 'sales_order_id', 'returned_at', 'note', 'total_amount', 'receivable_credit_amount', 'cash_refund_amount', 'created_by'];
    protected $casts = ['returned_at' => 'date:Y-m-d'];
    public function order(): BelongsTo { return $this->belongsTo(SaleOrder::class, 'sales_order_id'); }
    public function lines(): HasMany { return $this->hasMany(SaleReturnLine::class, 'sales_return_id'); }
}
