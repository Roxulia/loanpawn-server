<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class AdminLicenseGrant extends BaseDataObject
{
    public function __construct(
        public int $tenantId,
        public int $planId,
        public string $effective,
        public ?int $durationMonths,
        public string $reason,
    ) {}
}
