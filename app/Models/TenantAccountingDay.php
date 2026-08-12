<?php

namespace App\Models;

use App\Enums\AccountingDayClosingSource;
use App\Enums\AccountingDayOpeningSource;
use App\Enums\AccountingDayStatus;
use App\Models\CoreModule\TenantUser;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantAccountingDay extends Model
{
    use BelongToTenant;

    protected $fillable = [
        'tenant_id',
        'business_date',
        'timezone',
        'status',
        'opened_at',
        'opened_by',
        'opening_source',
        'closing_started_at',
        'closed_at',
        'effective_closed_at',
        'closed_by',
        'closing_source',
        'close_metadata',
        'update_key',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'status' => AccountingDayStatus::class,
            'opened_at' => 'datetime',
            'opening_source' => AccountingDayOpeningSource::class,
            'closing_started_at' => 'datetime',
            'closed_at' => 'datetime',
            'effective_closed_at' => 'datetime',
            'closing_source' => AccountingDayClosingSource::class,
            'close_metadata' => 'array',
            'update_key' => 'integer',
        ];
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'closed_by');
    }

    public function summaries(): HasMany
    {
        return $this->hasMany(TenantAccountingDaySummary::class, 'accounting_day_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(TenantAccountingTransactions::class, 'accounting_day_id');
    }
}
