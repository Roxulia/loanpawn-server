<?php

namespace App\Models\PawnModule;

use App\Models\CoreModule\TenantUser;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PawnRedemption extends Model
{
    use BelongToTenant;

    protected $fillable = [
        'tenant_id',
        'slip_number',
        'slip_id',
        'gross_amount',
        'net_amount',
        'interest_amount',
        'received_amount',
        'change_amount',
        'redemption_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'received_amount' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'redemption_at' => 'datetime',
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
