<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\TenantUser;

class TenantUserAuthSession extends BaseDataObject
{
    public TenantUserDetail $user;
    public string $guard;
    public string $tokenName;
    public string $tokenValue;
    public string $tenantCode;
    public string $tenantHeaderName;
    public string $tenantHeaderValue;

    public static function fromModel(
        TenantUser $user,
        string $tenantCode,
        string $tokenName,
        string $tokenValue,
        string $guard = 'tenantuser',
    ): self {
        $detail = new self();
        $detail->user = TenantUserDetail::fromModel($user);
        $detail->guard = $guard;
        $detail->tokenName = $tokenName;
        $detail->tokenValue = $tokenValue;
        $detail->tenantCode = $tenantCode;
        $detail->tenantHeaderName = 'X-Tenant-Code';
        $detail->tenantHeaderValue = $tenantCode;

        return $detail;
    }
}
