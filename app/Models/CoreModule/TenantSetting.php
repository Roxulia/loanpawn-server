<?php

namespace App\Models\CoreModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSetting extends Model
{
    use BelongToTenant;

    protected $fillable = [
        'tenant_id',
        'key',
        'value',
        'category',
        'default_currency_id',
        'reporting_currency_id',
        'update_key',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'string',
            'default_currency_id' => 'integer',
            'reporting_currency_id' => 'integer',
        ];
    }

    public function defaultCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'default_currency_id');
    }

    public function reportingCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'reporting_currency_id');
    }
}
