<?php

namespace App\Models\PlatformModule;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TenantLicense extends Model
{
    protected $fillable = [
        'tenant_id',
        'license_key',
        'plan_type',
        'status',
        'starts_at',
        'expires_at',
        'activated_at',
        'approved_by',
        'notes',
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

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'approved_by');
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(LicenseStatusLog::class, 'license_id');
    }

    public function scheduledPlanTransition(): HasOne
    {
        return $this->hasOne(TenantLicensePlanTransition::class)
            ->where('status', 'scheduled')
            ->where('is_deleted', false);
    }
}
