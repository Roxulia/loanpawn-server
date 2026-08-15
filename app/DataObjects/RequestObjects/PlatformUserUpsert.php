<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class PlatformUserUpsert extends BaseDataObject
{
    public function __construct(
        public string $name,
        public string $email,
        public ?string $phone = null,
        public string $status = 'active',
        public int $updateKey = 0
    ) {
    }
}
