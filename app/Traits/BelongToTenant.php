<?php

namespace App\Traits;

use App\Models\PlatformModule\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongToTenant
{
    protected static function tenantContext(): TenantContext
    {
        return app(TenantContext::class);
    }

    public static function bootBelongToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $tenantId = static::resolveTenantId();

            if ($tenantId !== null) {
                $builder->where($builder->qualifyColumn('tenant_id'), $tenantId);
            }
        });

        static::creating(function (Model $model): void {
            $tenantId = static::resolveTenantId();

            if ($tenantId !== null && empty($model->getAttribute('tenant_id'))) {
                $model->setAttribute('tenant_id', $tenantId);
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->withoutGlobalScope('tenant')
            ->where($query->qualifyColumn('tenant_id'), $tenantId);
    }

    protected static function resolveTenantId(): ?int
    {
        if (! app()->bound(TenantContext::class)) {
            return null;
        }

        return static::tenantContext()->id();
    }
}
