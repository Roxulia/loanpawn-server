<?php

namespace App\Models\CoreModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantBusinessLoanInterestAccrual extends Model
{
    use BelongToTenant;

    protected $fillable = [
        'tenant_id',
        'business_loan_id',
        'principal_amount',
        'calculated_interest',
        'paid_amount',
        'compounded_amount',
        'compounded_at',
        'start_period_at',
        'end_period_at',
        'period_timezone',
        'is_paid'
    ];
    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'start_period_at' => 'datetime',
            'end_period_at' => 'datetime',
            'compounded_at' => 'datetime'
        ];
    }
    public function businessLoan(): BelongsTo
    {
        return $this->belongsTo(TenantBusinessLoan::class, 'business_loan_id');
    }
}
