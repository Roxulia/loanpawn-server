<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantCustomerCreate extends BaseDataObject
{
    public function __construct(
        public string $name,
        public ?string $nrc = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $address = null,
        public int $trustScore = 0,
        public ?string $note = null,
        public ?int $tenantId = null,
        public ?int $createdBy = null,
    ) {
    }
}
