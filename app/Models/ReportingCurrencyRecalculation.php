<?php

namespace App\Models;

use App\Models\CoreModule\Currency;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportingCurrencyRecalculation extends Model
{
    use BelongToTenant;

    public const ACTIVE_STATUSES = ['queued', 'processing', 'waiting_for_rates', 'failed'];

    protected $fillable = [
        'tenant_id', 'previous_reporting_currency_id', 'requested_reporting_currency_id',
        'window_start', 'window_end', 'status', 'missing_rates', 'attempt_count',
        'error_message', 'queued_at', 'started_at', 'completed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'window_start' => 'date',
            'window_end' => 'date',
            'missing_rates' => 'array',
            'attempt_count' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function previousReportingCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'previous_reporting_currency_id');
    }

    public function requestedReportingCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'requested_reporting_currency_id');
    }
}
