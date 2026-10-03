<?php
namespace App\Models\PurchasingModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPayableAdjustment extends Model
{
    use BelongToTenant;
    protected $fillable = ['tenant_id','code','supplier_payable_id','adjustment_type','amount','source_type','source_code','accounting_transaction_id','created_by'];
    protected $casts = ['amount'=>'decimal:2'];
    public function payable(): BelongsTo { return $this->belongsTo(SupplierPayable::class,'supplier_payable_id'); }
}