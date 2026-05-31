<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantCustomerUpdate extends BaseDataObject
{
    public function __construct(
        public int $customerId,
        public string $code,
        public int $updateKey,
        public ?string $name = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $address = null,
        public ?int $trustScore = null,
        public ?string $note = null,
    ) {
    }
}
