<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantLenderUpsert extends BaseDataObject
{
    public function __construct(
        public string $name,
        public ?string $nrc = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $address = null,
        public ?string $note = null,
        public int $updateKey = 0,
    ) {}
}
