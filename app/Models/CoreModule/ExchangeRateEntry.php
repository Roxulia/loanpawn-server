<?php

namespace App\Models\CoreModule;

use App\Models\PlatformModule\PlatformAdmin;
use App\Models\PlatformModule\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRateEntry extends Model
{
    protected $fillable = ['code', 'tenant_id', 'scope_key', 'exchange_rate_pair_id', 'buying_rate', 'selling_rate', 'effective_date', 'observed_at', 'source', 'idempotency_key', 'is_void', 'voided_at', 'void_reason', 'created_by_tenant_user_id', 'created_by_platform_admin_id', 'voided_by_tenant_user_id', 'voided_by_platform_admin_id'];

    protected function casts(): array
    {
        return ['buying_rate' => 'decimal:12', 'selling_rate' => 'decimal:12', 'effective_date' => 'date', 'observed_at' => 'datetime', 'is_void' => 'boolean', 'voided_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function pair(): BelongsTo
    {
        return $this->belongsTo(ExchangeRatePair::class, 'exchange_rate_pair_id')->withTrashed();
    }

    public function tenantCreator(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'created_by_tenant_user_id');
    }

    public function adminCreator(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'created_by_platform_admin_id');
    }
}
