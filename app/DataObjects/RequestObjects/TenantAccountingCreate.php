<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantAccountingCreate extends BaseDataObject
{
    public function __construct(
        public string $description,
        public string $transactionType,
        public float $amount,
        public ?int $tenantId = null,
        public ?int $createdBy = null,
        public ?int $referenceId = null,
        public ?string $referenceType = null,
    ) {
    }
}
