<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantUserSubdomainLogin extends BaseDataObject
{
    public function __construct(
        public string $email,
        public string $password,
    ) {
    }
}
