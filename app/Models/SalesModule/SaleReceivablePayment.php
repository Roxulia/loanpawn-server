<?php

namespace App\Models\SalesModule;

use App\Models\FinancialAccount;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReceivablePayment extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'code', 'sale_receivable_id', 'paid_at', 'amount', 'payment_amount', 'change_amount', 'financial_account_id', 'accounting_transaction_id', 'reference', 'note', 'created_by'];
    protected $casts = ['paid_at' => 'date:Y-m-d', 'amount' => 'decimal:2', 'payment_amount' => 'decimal:2', 'change_amount' => 'decimal:2'];

    // Link each collection to the receivable and account that received it.
    public function receivable(): BelongsTo { return $this->belongsTo(SaleReceivable::class, 'sale_receivable_id'); }
    public function financialAccount(): BelongsTo { return $this->belongsTo(FinancialAccount::class); }
}
