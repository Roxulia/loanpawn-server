<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class AdminLicenseExtension extends BaseDataObject
{
    public function __construct(
        public int $tenantId,
        public int $extensionMonths,
        public string $reason,
    ) {}
}
