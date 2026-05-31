<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantRequestCreate extends BaseDataObject
{
    public function __construct(
        public int $tenantId,
        public string $requestType,
        public ?string $requestedPlanType = null,
        public ?int $extensionMonths = null,
        public string $currency = 'MMK',
        public ?string $note = null,
    ) {
    }
}
