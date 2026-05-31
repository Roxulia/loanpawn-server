<?php

namespace App\Models\PlatformModule;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManualPaymentRequest extends Model
{
    protected $fillable = [
        'platform_user_id',
        'code',
        'tenant_request_id',
        'tenant_id',
        'amount',
        'currency',
        'payment_reference',
        'note',
        'status',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function platformUser(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class);
    }

    public function tenantRequest(): BelongsTo
    {
        return $this->belongsTo(TenantRequest::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'reviewed_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ManualPaymentAttachment::class);
    }

}
