<?php

namespace App\Models\PawnModule;

use App\Models\CoreModule\InterestType;
use App\Models\CoreModule\TenantCustomer;
use App\Models\CoreModule\TenantUser;
use App\Models\FinancialAccount;
use App\Traits\BelongToTenant;
use Database\Factories\PawnLoanContractSlipFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PawnLoanContractSlip extends Model
{
    use BelongToTenant;
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): PawnLoanContractSlipFactory
    {
        return PawnLoanContractSlipFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'slip_no',
        'customer_id',
        'account_id',
        'loan_amount',
        'interest_rate',
        'interest_type_id',
        'expire_at',
        'last_interest_added_at',
        'last_interest_paid_at',
        'status',
        'notes',
        'created_by',
        'expiry_quota',
        'expiry_quota_type',
        'compound_schedule_enabled',
        'compound_every',
        'compound_every_type',
        'next_compound_at',
        'last_compounded_at',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'loan_amount' => 'decimal:2',
            'interest_rate' => 'decimal:4',
            'expire_at' => 'datetime',
            'last_interest_added_at' => 'datetime',
            'last_interest_paid_at' => 'datetime',
            'compound_schedule_enabled' => 'boolean',
            'next_compound_at' => 'datetime',
            'last_compounded_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'account_id');
    }

    public function interestType(): BelongsTo
    {
        return $this->belongsTo(InterestType::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'created_by');
    }

    public function slipItems(): HasMany
    {
        return $this->hasMany(PawnCollateralItem::class, 'loan_contract_id')
            ->where('is_deleted', false);
    }
}
