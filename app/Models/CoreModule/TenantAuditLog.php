<?php

namespace App\Models\CoreModule;

use App\Models\PlatformModule\PlatformAdmin;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantAuditLog extends Model
{
    use BelongToTenant;

    protected $fillable = [
        'tenant_id',
        'tenant_code',
        'actor_user_id',
        'actor_admin_id',
        'action',
        'target_type',
        'target_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'actor_user_id');
    }

    public function actorAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'actor_admin_id');
    }
}
