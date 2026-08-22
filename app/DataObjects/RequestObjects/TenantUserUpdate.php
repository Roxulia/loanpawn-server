<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantUserUpdate extends BaseDataObject
{
    public int $userId;
    public string $code;
    public int $updateKey;
    public ?int $roleId;
    public ?string $name;
    public ?string $nrc;
    public ?string $email;
    public ?string $phone;
    public ?string $address;
    public ?string $password;

    public function __construct(
        int $userId,
        string $code,
        int $updateKey,
        ?string $name = null,
        ?string $nrc = null,
        ?string $password = null,
        ?string $email = null,
        ?string $phone = null,
        ?string $address = null,
        ?int $roleId = null,
    ) {
        $this->userId = $userId;
        $this->roleId = $roleId;
        $this->name = $name;
        $this->nrc = $nrc;
        $this->email = $email;
        $this->phone = $phone;
        $this->address = $address;
        $this->password = $password;
        $this->code = $code;
        $this->updateKey = $updateKey;
    }
}
