<?php

namespace App\Models\CoreModule;

use App\Models\FinancialAccount;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantBusinessLoanPayment extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'business_loan_id', 'payment_account_id', 'code', 'allocation_order', 'payment_amount', 'principal_paid', 'interest_paid', 'payment_at', 'created_by'];
    protected function casts(): array { return ['payment_at' => 'datetime']; }
    public function businessLoan(): BelongsTo { return $this->belongsTo(TenantBusinessLoan::class, 'business_loan_id'); }
    public function paymentAccount(): BelongsTo { return $this->belongsTo(FinancialAccount::class, 'payment_account_id'); }
}
