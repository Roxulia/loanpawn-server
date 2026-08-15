<?php

namespace App\Models;

use App\Models\CoreModule\Currency;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialAccountTransactionMonthlySummary extends Model
{
    use BelongToTenant;

    protected $fillable = [
        'tenant_id', 'month_start', 'financial_account_id', 'currency_id',
        'total_debit', 'total_credit', 'net_movement', 'transaction_count', 'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'month_start' => 'date',
            'total_debit' => 'decimal:4',
            'total_credit' => 'decimal:4',
            'net_movement' => 'decimal:4',
            'transaction_count' => 'integer',
            'calculated_at' => 'datetime',
        ];
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
