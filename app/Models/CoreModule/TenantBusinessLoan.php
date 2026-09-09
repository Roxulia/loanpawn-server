<?php

namespace App\Models\CoreModule;

use App\Models\FinancialAccount;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TenantBusinessLoan extends Model
{
    use BelongToTenant;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'lender_id', 'receipt_account_id', 'code', 'update_key', 'is_deleted', 'amount',
        'principal_balance', 'apply_interest', 'interest_rate', 'interest_type_id', 'interest_anchor_at',
        'last_interest_paid_at', 'compound_schedule_enabled', 'compound_every', 'compound_every_type',
        'next_compound_at', 'last_compounded_at', 'description', 'tag', 'is_paid', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'principal_balance' => 'decimal:2', 'interest_rate' => 'decimal:4',
            'apply_interest' => 'boolean', 'is_paid' => 'boolean', 'is_deleted' => 'boolean',
            'interest_anchor_at' => 'datetime', 'last_interest_paid_at' => 'datetime',
            'compound_schedule_enabled' => 'boolean', 'next_compound_at' => 'datetime', 'last_compounded_at' => 'datetime',
        ];
    }

    public function lender(): BelongsTo { return $this->belongsTo(TenantLender::class, 'lender_id')->withTrashed(); }
    public function receiptAccount(): BelongsTo { return $this->belongsTo(FinancialAccount::class, 'receipt_account_id'); }
    public function interestType(): BelongsTo { return $this->belongsTo(InterestType::class, 'interest_type_id'); }
    public function interestAccruals(): HasMany { return $this->hasMany(TenantBusinessLoanInterestAccrual::class, 'business_loan_id'); }
    public function payments(): HasMany { return $this->hasMany(TenantBusinessLoanPayment::class, 'business_loan_id'); }

    public function getOutstandingInterestAttribute(): float
    {
        return (float) $this->interestAccruals->sum(fn (TenantBusinessLoanInterestAccrual $row): float => max((float) $row->calculated_interest - (float) $row->paid_amount - (float) $row->compounded_amount, 0));
    }
}
