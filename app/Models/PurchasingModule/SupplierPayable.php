<?php
namespace App\Models\PurchasingModule;

use App\Models\CoreModule\Currency;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierPayable extends Model
{
    use BelongToTenant;
    protected $fillable = ['tenant_id','code','supplier_id','purchase_receipt_id','currency_id','original_amount','balance_amount','status','created_by'];
    protected $casts = ['original_amount'=>'decimal:2','balance_amount'=>'decimal:2'];
    public function supplier(): BelongsTo { return $this->belongsTo(PurchaseSupplier::class); }
    public function receipt(): BelongsTo { return $this->belongsTo(PurchaseReceipt::class,'purchase_receipt_id'); }
    public function currency(): BelongsTo { return $this->belongsTo(Currency::class); }
    public function payments(): HasMany { return $this->hasMany(SupplierPayablePayment::class); }
    public function adjustments(): HasMany { return $this->hasMany(SupplierPayableAdjustment::class); }
}