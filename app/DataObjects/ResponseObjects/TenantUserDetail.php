<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\TenantUser;
use App\Support\TenantPermissionColumns;

class TenantUserDetail extends BaseDataObject
{
    /**
     * Create a new class instance.
     */
    public int $id;
    public int $tenantId;
    public string $code;
    public int $updateKey;
    public ?int $roleId;
    public string $username;
    public string $name;
    public string $nrc;
    public ?string $email;
    public string $phone;
    public ?string $address;
    public string $status;
    public bool $isDeleted;
    public ?string $lastLoginAt;
    public ?int $createdBy;
    public ?string $roleName;
    public array $permissions;
    public string $preferLang;

    public function __construct()
    {
        //
    }

    public static function fromModel(TenantUser $user): self
    {
        $detail = new self();
        $detail->id = $user->id;
        $detail->tenantId = $user->tenant_id;
        $detail->code = $user->code;
        $detail->updateKey = (int) $user->update_key;
        $detail->roleId = $user->role_id;
        $detail->username = $user->username;
        $detail->name = $user->name;
        $detail->nrc = $user->nrc;
        $detail->email = $user->email;
        $detail->phone = $user->phone;
        $detail->address = $user->address;
        $detail->status = $user->status;
        $detail->isDeleted = (bool) $user->is_deleted;
        $detail->lastLoginAt = $user->last_login_at?->toISOString();
        $detail->createdBy = $user->created_by;
        $detail->roleName = $user->role?->name;
        $detail->permissions = TenantPermissionColumns::effectivePermissions(
            TenantPermissionColumns::enabledFromModel($user->permission ?? $user->role)
        );
        $detail->preferLang = $user->prefer_lang ?? 'en';
        return $detail;
    }
}
