<?php

namespace App\Models\CoreModule;

use App\Models\PlatformModule\PlatformAdmin;
use App\Models\PlatformModule\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExchangeRatePair extends Model
{
    use SoftDeletes;

    protected $fillable = ['tenant_id', 'scope_key', 'code', 'base_currency_id', 'quote_currency_id', 'is_default', 'is_active', 'update_key', 'created_by_tenant_user_id', 'created_by_platform_admin_id'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function baseCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'base_currency_id')->withTrashed();
    }

    public function quoteCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'quote_currency_id')->withTrashed();
    }

    public function tenantCreator(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'created_by_tenant_user_id');
    }

    public function adminCreator(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'created_by_platform_admin_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ExchangeRateEntry::class);
    }
}
