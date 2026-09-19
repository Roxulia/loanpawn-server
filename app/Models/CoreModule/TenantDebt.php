<?php

namespace App\Models\CoreModule;

use App\Models\FinancialAccount;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Traits\BelongToTenant;
use Database\Factories\TenantDebtFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantDebt extends Model
{
    use BelongToTenant;
    use HasFactory;

    protected static function newFactory(): TenantDebtFactory
    {
        return TenantDebtFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'code',
        'created_account_id',
        'accept_account_id',
        'slip_id',
        'customer_id',
        'amount',
        'apply_interest',
        'principal_balance',
        'interest_rate',
        'interest_type_id',
        'interest_anchor_at',
        'last_interest_paid_at',
        'compound_schedule_enabled',
        'compound_every',
        'compound_every_type',
        'next_compound_at',
        'last_compounded_at',
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
            'apply_interest' => 'boolean',
            'principal_balance' => 'decimal:2',
            'interest_rate' => 'decimal:4',
            'interest_anchor_at' => 'datetime',
            'last_interest_paid_at' => 'datetime',
            'compound_schedule_enabled' => 'boolean',
            'next_compound_at' => 'datetime',
            'last_compounded_at' => 'datetime',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id');
    }

    public function interestType(): BelongsTo
    {
        return $this->belongsTo(InterestType::class, 'interest_type_id');
    }

    public function interestAccruals(): HasMany
    {
        return $this->hasMany(TenantDebtInterestAccrual::class, 'debt_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(TenantDebtPayment::class, 'debt_id');
    }

    public function getOutstandingInterestAttribute(): float
    {
        if ($this->relationLoaded('interestAccruals')) {
            return (float) $this->interestAccruals->sum(
                fn (TenantDebtInterestAccrual $row): float => max((float) $row->calculated_interest - (float) $row->paid_amount, 0)
            );
        }

        return max((float) ($this->total_interest_accrued ?? 0) - (float) ($this->total_interest_paid ?? 0), 0);
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
