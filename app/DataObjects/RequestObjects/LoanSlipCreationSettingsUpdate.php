<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class LoanSlipCreationSettingsUpdate extends BaseDataObject
{
    public function __construct(
        public bool $customerInfoRequired,
        public int $updateKey,
    ) {}
}
