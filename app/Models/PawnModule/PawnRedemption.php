<?php

namespace App\Models\PawnModule;

use App\Models\CoreModule\TenantUser;
use App\Models\FinancialAccount;
use App\Traits\BelongToTenant;
use Database\Factories\PawnRedemptionFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PawnRedemption extends Model
{
    use BelongToTenant;
    use HasFactory;

    protected static function newFactory(): PawnRedemptionFactory
    {
        return PawnRedemptionFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'slip_number',
        'slip_id',
        'account_id',
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

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'created_by');
    }
}
