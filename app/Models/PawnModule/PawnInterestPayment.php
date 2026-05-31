<?php

namespace App\Models\PawnModule;

use App\Models\CoreModule\TenantUser;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PawnInterestPayment extends Model
{
    use BelongToTenant;

    protected $fillable = [
        'tenant_id',
        'slip_id',
        'payment_amount',
        'change_amount',
        'calculated_interest',
        'payment_date',
        'notes',
        'created_by',
        'start_period',
        'end_period',
        'is_paid',
    ];

    protected function casts(): array
    {
        return [
            'payment_amount' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'calculated_interest' => 'decimal:2',
            'payment_date' => 'date',
            'start_period' => 'date',
            'end_period' => 'date',
            'is_paid' => 'boolean',
        ];
    }

    public function slip(): BelongsTo
    {
        return $this->belongsTo(PawnLoanContractSlip::class, 'slip_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'created_by');
    }
}
