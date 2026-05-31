<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantDefaultUserPasswordUpdate extends BaseDataObject
{
    public function __construct(
        public string $defaultTenantUserPassword,
        public int $updateKey,
    ) {
    }
}
