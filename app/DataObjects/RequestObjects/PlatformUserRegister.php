<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class PlatformUserRegister extends BaseDataObject
{
    /**
     * Create a new class instance.
     */
    public string $email;
    public string $name;
    public string $password;
    public function __construct(
        string $email,
        string $name,
        string $password
    )
    {
        $this->email = $email;
        $this->name = $name;
        $this->password = $password;
    }
}
