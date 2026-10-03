<?php
namespace App\Models\PurchasingModule;

use App\Models\FinancialAccount;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPayablePayment extends Model
{
    use BelongToTenant;
    protected $fillable = ['tenant_id','code','supplier_payable_id','paid_at','amount','financial_account_id','accounting_transaction_id','reference','note','created_by'];
    protected $casts = ['paid_at'=>'date:Y-m-d','amount'=>'decimal:2'];
    public function payable(): BelongsTo { return $this->belongsTo(SupplierPayable::class,'supplier_payable_id'); }
    public function financialAccount(): BelongsTo { return $this->belongsTo(FinancialAccount::class,'financial_account_id'); }
}