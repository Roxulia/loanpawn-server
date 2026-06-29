<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantCapitalUpdate extends BaseDataObject
{
    public function __construct(
        public int $capitalId,
        public string $code,
        public int $updateKey,
        public ?string $description = null,
        public ?float $amount = null,
    ) {
    }
}
