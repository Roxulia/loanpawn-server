<?php

namespace App\Models\CoreModule;

use App\Models\PawnModule\PawnLoanContractSlip;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantDebt extends Model
{
    use BelongToTenant;

    protected $fillable = [
        'tenant_id',
        'code',
        'slip_id',
        'amount',
        'description',
        'tag',
        'is_paid',
        'accepted_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_paid' => 'boolean',
        ];
    }

    public function slip(): BelongsTo
    {
        return $this->belongsTo(PawnLoanContractSlip::class, 'slip_id');
    }

    public function acceptedByUser(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'accepted_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'created_by');
    }

}
