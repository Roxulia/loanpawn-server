<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantUserCreate extends BaseDataObject
{
    public ?int $tenantId;
    public ?int $roleId;
    public string $name;
    public string $nrc;
    public ?string $email;
    public string $phone;
    public ?string $address;
    public ?string $password;
    public string $status;
    public ?int $createdBy;

    public function __construct(
        string $name,
        string $nrc,
        string $phone,
        ?string $password,
        ?int $tenantId = null,
        ?string $email = null,
        ?string $address = null,
        ?int $roleId = null,
        string $status = 'active',
        ?int $createdBy = null
    )
    {
        $this->tenantId = $tenantId;
        $this->roleId = $roleId;
        $this->name = $name;
        $this->nrc = $nrc;
        $this->email = $email;
        $this->phone = $phone;
        $this->address = $address;
        $this->password = $password;
        $this->status = $status;
        $this->createdBy = $createdBy;
    }
}
