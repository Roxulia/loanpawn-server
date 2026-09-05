<?php

namespace App\Models\CoreModule;

use App\Models\FinancialAccount;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantDebtPayment extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'code', 'debt_id', 'accept_account_id', 'allocation_order', 'payment_amount', 'principal_paid', 'interest_paid', 'change_amount', 'payment_at', 'created_by'];

    protected function casts(): array
    {
        return [
            'payment_amount' => 'decimal:2',
            'principal_paid' => 'decimal:2',
            'interest_paid' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'payment_at' => 'datetime',
        ];
    }

    public function debt(): BelongsTo { return $this->belongsTo(TenantDebt::class, 'debt_id'); }
    public function acceptAccount(): BelongsTo { return $this->belongsTo(FinancialAccount::class, 'accept_account_id'); }
}
