<?php

namespace App\Models;

use App\Models\CoreModule\TenantUser;
use App\Models\PlatformModule\Tenant;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantUserNotification extends Model
{
    use BelongToTenant;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'tenant_id', 'tenant_user_id', 'reporting_currency_recalculation_id',
        'type', 'status', 'data', 'read_at',
    ];

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tenantUser(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class);
    }

    public function reportingCurrencyRecalculation(): BelongsTo
    {
        return $this->belongsTo(ReportingCurrencyRecalculation::class);
    }
}
