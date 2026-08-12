<?php

namespace App\Models;

use App\Enums\AccountingCategory;
use App\Models\CoreModule\Currency;
use App\Models\CoreModule\TenantAccounting;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantAccountingTransactions extends TenantAccounting
{
    protected $table = 'tenant_accounting_transactions';

    protected $fillable = [
        'tenant_id',
        'accounting_day_id',
        'business_date',
        'transaction_direction',
        'accounting_category',
        'amount',
        'currency_id',
        'reporting_amount',
        'exchange_rate',
        'description',
        'reference_id',
        'reference_type',
        'occurred_at',
        'created_by',
        'legacy_accounting_id',
        'update_key',
        'is_deleted',
    ];

    protected function casts(): array
    {
        return [
            'accounting_category' => AccountingCategory::class,
            'business_date' => 'date',
            'amount' => 'decimal:4',
            'reporting_amount' => 'decimal:4',
            'exchange_rate' => 'decimal:10',
            'occurred_at' => 'datetime',
            'legacy_accounting_id' => 'integer',
            'update_key' => 'integer',
            'is_deleted' => 'boolean',
        ];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function accountingDay(): BelongsTo
    {
        return $this->belongsTo(TenantAccountingDay::class, 'accounting_day_id');
    }

    public function getTransactionTypeAttribute(): ?string
    {
        return $this->getAttribute('transaction_direction');
    }

    public function getDescriptionAttribute(?string $value): string
    {
        return $value ?? '';
    }
}
