<?php

namespace App\Models\CoreModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantDebtInterestAccrual extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'debt_id', 'principal_amount', 'calculated_interest', 'paid_amount', 'start_period_at', 'end_period_at', 'is_paid'];

    protected function casts(): array
    {
        return [
            'principal_amount' => 'decimal:2',
            'calculated_interest' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'start_period_at' => 'datetime',
            'end_period_at' => 'datetime',
            'is_paid' => 'boolean',
        ];
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(TenantDebt::class, 'debt_id');
    }
}
