<?php
namespace App\Models\PurchasingModule;

use App\Models\CoreModule\Currency;
use App\Models\CoreModule\InterestType;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


class SupplierPayable extends Model
{
    use BelongToTenant;
    protected $fillable = ['tenant_id','code','supplier_id','purchase_receipt_id','purchase_order_id','currency_id','original_amount','balance_amount','apply_interest','interest_rate','interest_type_id','status','created_by'];
    protected $casts = ['original_amount'=>'decimal:2','balance_amount'=>'decimal:2','apply_interest'=>'boolean','interest_rate'=>'decimal:4'];
    public function supplier(): BelongsTo { return $this->belongsTo(PurchaseSupplier::class); }
    public function receipt(): BelongsTo { return $this->belongsTo(PurchaseReceipt::class,'purchase_receipt_id'); }
    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
    public function currency(): BelongsTo { return $this->belongsTo(Currency::class); }
    public function interestType(): BelongsTo { return $this->belongsTo(InterestType::class); }
    public function payments(): HasMany { return $this->hasMany(SupplierPayablePayment::class); }
    public function adjustments(): HasMany { return $this->hasMany(SupplierPayableAdjustment::class); }
}
