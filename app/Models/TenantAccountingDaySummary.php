<?php

namespace App\Models;

use App\Models\CoreModule\Currency;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantAccountingDaySummary extends Model
{
    use BelongToTenant;

    protected $fillable = [
        'tenant_id',
        'accounting_day_id',
        'currency_id',
        'opening_balance',
        'total_incoming',
        'total_outgoing',
        'closing_balance',
        'revenue',
        'expense',
        'profit',
        'category_totals',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:4',
            'total_incoming' => 'decimal:4',
            'total_outgoing' => 'decimal:4',
            'closing_balance' => 'decimal:4',
            'revenue' => 'decimal:4',
            'expense' => 'decimal:4',
            'profit' => 'decimal:4',
            'category_totals' => 'array',
        ];
    }

    public function accountingDay(): BelongsTo
    {
        return $this->belongsTo(TenantAccountingDay::class, 'accounting_day_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
