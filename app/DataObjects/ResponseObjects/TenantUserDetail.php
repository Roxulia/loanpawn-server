<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\TenantUser;
use App\Support\TenantPermissionColumns;
use App\Utility\NrcHelper;

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
    public string $nrc_state;
    public string $nrc_township;
    public string $nrc_citizen;
    public string $nrc_number;
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
    public array $financialAccounts;

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
            array_unique([
                ...TenantPermissionColumns::enabledFromModel($user->role),
                ...TenantPermissionColumns::enabledFromModel($user->permission),
            ])
        );
        $detail->preferLang = $user->prefer_lang ?? 'en';
        $detail->financialAccounts = $user->relationLoaded('financialAccounts')
            ? $user->financialAccounts
                ->filter(fn ($account) => ! $account->is_deleted)
                ->map(fn ($account) => FinancialAccountSummary::fromModel($account))
                ->values()
                ->all()
            : [];
        $nrc_decomposed = NrcHelper::decomposeNRC($user->nrc);
        $detail->nrc_state = $nrc_decomposed!==null ? $nrc_decomposed['state'] : "";
        $detail->nrc_township = $nrc_decomposed!==null ? $nrc_decomposed['township'] : "";
        $detail->nrc_citizen= $nrc_decomposed!==null ? $nrc_decomposed['citizen'] : "";
        $detail->nrc_number = $nrc_decomposed!==null ? $nrc_decomposed['number'] : "";
        return $detail;
    }
}
