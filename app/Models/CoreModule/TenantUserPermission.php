<?php

namespace App\Models\CoreModule;

use App\Support\TenantPermissionColumns;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantUserPermission extends Model
{
    use BelongToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return array_fill_keys(TenantPermissionColumns::all(), 'boolean');
    }

    public function tenantUser(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'tenant_user_id');
    }
}
