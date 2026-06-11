<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\TenantUser;
class TenantUserCreateResponse extends BaseDataObject
{
    /**
     * Create a new class instance.
     */
    public string $username;
    public string $name;
    public ?string $email;
    public ?string $password;
    public ?string $roleName;

    public function __construct()
    {
        //
    }

    public static function fromModel(TenantUser $user,string $defaultPassword): self
    {
        $detail = new self();
        $detail->username = $user->username;
        $detail->name = $user->name;
        $detail->email = $user->email;
        $detail->roleName = $user->role?->name;
        $detail->password = $defaultPassword;
        return $detail;
    }
}
