<?php

namespace App\Models;

use App\Models\CoreModule\Currency;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantAccountingMonthlySummary extends Model
{
    use BelongToTenant;

    protected $fillable = [
        'tenant_id', 'month_start', 'currency_id', 'reporting_currency_id',
        'total_incoming', 'total_outgoing', 'total_internal', 'net_movement',
        'reporting_total_incoming', 'reporting_total_outgoing',
        'reporting_total_internal', 'reporting_net_movement',
        'transaction_count', 'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'month_start' => 'date',
            'total_incoming' => 'decimal:4',
            'total_outgoing' => 'decimal:4',
            'total_internal' => 'decimal:4',
            'net_movement' => 'decimal:4',
            'reporting_total_incoming' => 'decimal:4',
            'reporting_total_outgoing' => 'decimal:4',
            'reporting_total_internal' => 'decimal:4',
            'reporting_net_movement' => 'decimal:4',
            'transaction_count' => 'integer',
            'calculated_at' => 'datetime',
        ];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function reportingCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'reporting_currency_id');
    }
}
