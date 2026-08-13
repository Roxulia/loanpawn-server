<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantTimezoneUpdate extends BaseDataObject
{
    public function __construct(public string $timezone, public int $updateKey) {}
}
