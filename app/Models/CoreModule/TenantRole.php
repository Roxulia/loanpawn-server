<?php

namespace App\Models\CoreModule;

use App\Support\TenantPermissionColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantRole extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            ...array_fill_keys(TenantPermissionColumns::all(), 'boolean'),
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(TenantUser::class, 'role_id');
    }

    public function setTenantIdAttribute(mixed $value): void
    {
        //
    }

    public function setPermissionsAttribute(array $permissions): void
    {
        foreach (TenantPermissionColumns::booleanPayload($permissions) as $permission => $enabled) {
            $this->attributes[$permission] = $enabled;
        }
    }
}
