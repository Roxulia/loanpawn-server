<?php

namespace App\Models\SalesModule;

use App\Models\FinancialAccount;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalePaymentRefund extends Model
{
    use BelongToTenant;
    protected $fillable = ['tenant_id', 'code', 'sales_payment_id', 'financial_account_id', 'accounting_transaction_id', 'refunded_at', 'amount', 'note', 'created_by'];
    protected $casts = ['refunded_at' => 'date:Y-m-d', 'amount' => 'decimal:2'];
    public function payment(): BelongsTo { return $this->belongsTo(SalePayment::class, 'sales_payment_id'); }
}
