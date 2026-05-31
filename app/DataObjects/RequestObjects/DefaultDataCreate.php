<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class DefaultDataCreate extends BaseDataObject
{
    public function __construct(
        public string $name,
        public ?string $code = null,
        public ?int $durationInDays = null,
    ) {
    }
}
