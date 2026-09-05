<?php

namespace App\Models\PawnModule;

use App\Models\CoreModule\TenantUser;
use App\Models\FinancialAccount;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PawnInterestPayment extends Model
{
    use BelongToTenant;

    protected $fillable = [
        'tenant_id',
        'slip_id',
        'created_account_id',
        'accept_account_id',
        'payment_amount',
        'change_amount',
        'calculated_interest',
        'payment_at',
        'notes',
        'created_by',
        'start_period_at',
        'end_period_at',
        'period_timezone',
        'is_paid',
        'update_key',
    ];

    protected function casts(): array
    {
        return [
            'payment_amount' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'calculated_interest' => 'decimal:2',
            'payment_at' => 'datetime',
            'start_period_at' => 'datetime',
            'end_period_at' => 'datetime',
            'is_paid' => 'boolean',
        ];
    }

    public function slip(): BelongsTo
    {
        return $this->belongsTo(PawnLoanContractSlip::class, 'slip_id');
    }

    public function createdAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'created_account_id');
    }

    public function acceptAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'accept_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'created_by');
    }
}
