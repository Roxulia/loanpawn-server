<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantUserPublicLogin extends BaseDataObject
{
    public function __construct(
        public string $tenantCode,
        public string $email,
        public string $password,
    ) {
    }
}
