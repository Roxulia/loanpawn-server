<?php

namespace App\Models\SalesModule;

use App\Models\CoreModule\Currency;
use App\Models\CoreModule\InterestType;
use App\Models\CoreModule\TenantCustomer;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleReceivable extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'code', 'customer_id', 'sales_order_id', 'sales_delivery_id', 'currency_id', 'legacy_tenant_debt_id', 'original_amount', 'balance_amount', 'apply_interest', 'interest_rate', 'interest_type_id', 'status', 'created_by'];
    protected $casts = ['original_amount' => 'decimal:2', 'balance_amount' => 'decimal:2', 'apply_interest' => 'boolean', 'interest_rate' => 'decimal:4'];

    // Link the receivable to its sale parties, delivery, and currency.
    public function customer(): BelongsTo { return $this->belongsTo(TenantCustomer::class); }
    public function order(): BelongsTo { return $this->belongsTo(SaleOrder::class, 'sales_order_id'); }
    public function delivery(): BelongsTo { return $this->belongsTo(SaleDelivery::class, 'sales_delivery_id'); }
    public function currency(): BelongsTo { return $this->belongsTo(Currency::class); }
    public function interestType(): BelongsTo { return $this->belongsTo(InterestType::class); }
    public function payments(): HasMany { return $this->hasMany(SaleReceivablePayment::class); }
    public function adjustments(): HasMany { return $this->hasMany(SaleReceivableAdjustment::class); }
}
