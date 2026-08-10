<?php

namespace App\Models\PlatformModule;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantLicensePlanTransition extends Model
{
    protected $fillable = [
        'tenant_license_id',
        'tenant_request_id',
        'from_plan_id',
        'to_plan_id',
        'from_plan_type',
        'to_plan_type',
        'starts_at',
        'expires_at',
        'status',
        'approved_by',
        'activated_at',
        'update_key',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(TenantLicense::class, 'tenant_license_id');
    }

    public function tenantRequest(): BelongsTo
    {
        return $this->belongsTo(TenantRequest::class);
    }

    public function fromPlan(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'from_plan_id');
    }

    public function toPlan(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'to_plan_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'approved_by');
    }
}
