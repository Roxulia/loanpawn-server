<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class AdminTenantProvision extends BaseDataObject
{
    public function __construct(
        public int $platformUserId,
        public string $name,
        public ?string $subdomain,
        public int $categoryId,
        public int $planId,
        public int $licenseMonths,
        public string $reason,
        public ?string $address = null,
        public ?string $phone = null,
        public ?string $city = null,
        public ?string $country = null,
    ) {}
}
