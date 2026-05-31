<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantContactUpdate extends BaseDataObject
{
    public function __construct(
        public int $updateKey,
        public ?string $address = null,
        public ?string $phone = null,
        public ?string $city = null,
        public ?string $country = null,
    ) {
    }
}
