<?php

namespace App\Models\SalesModule;

use App\Models\FinancialAccount;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalePayment extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'code', 'sales_order_id', 'financial_account_id', 'accounting_transaction_id', 'paid_at', 'amount', 'payment_amount', 'change_amount', 'reference', 'note', 'created_by'];
    protected $casts = ['paid_at' => 'date:Y-m-d', 'amount' => 'decimal:2', 'payment_amount' => 'decimal:2', 'change_amount' => 'decimal:2'];
    public function order(): BelongsTo { return $this->belongsTo(SaleOrder::class, 'sales_order_id'); }
    public function financialAccount(): BelongsTo { return $this->belongsTo(FinancialAccount::class); }
    public function refunds(): HasMany { return $this->hasMany(SalePaymentRefund::class, 'sales_payment_id'); }
}
