<?php

namespace App\Models\PlatformModule;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TenantRequest extends Model
{
    protected $fillable = [
        'tenant_id',
        'code',
        'platform_user_id',
        'request_type',
        'requested_plan_type',
        'requested_subdomain',
        'extension_months',
        'total_cost',
        'currency',
        'business_info',
        'request_status',
        'reviewed_by',
        'reviewed_at',
        'admin_review_note',
        'update_key',
    ];

    protected function casts(): array
    {
        return [
            'business_info' => 'array',
            'total_cost' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function platformUser(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'reviewed_by');
    }

    public function manualPaymentRequests(): HasMany
    {
        return $this->hasMany(ManualPaymentRequest::class);
    }

    public function planTransition(): HasOne
    {
        return $this->hasOne(TenantLicensePlanTransition::class);
    }

}
