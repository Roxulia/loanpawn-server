<?php
namespace App\Models\PurchasingModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierCreditAllocation extends Model
{
    use BelongToTenant;
    protected $fillable = ['tenant_id','code','supplier_credit_id','supplier_payable_id','amount','accounting_transaction_id','asset_accounting_transaction_id','created_by'];
    protected $casts = ['amount'=>'decimal:2'];
    public function credit(): BelongsTo { return $this->belongsTo(SupplierCredit::class,'supplier_credit_id'); }
    public function payable(): BelongsTo { return $this->belongsTo(SupplierPayable::class,'supplier_payable_id'); }
}