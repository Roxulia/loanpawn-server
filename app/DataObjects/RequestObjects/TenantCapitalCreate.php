<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantCapitalCreate extends BaseDataObject
{
    public function __construct(
        public string $description,
        public float $amount,
        public ?int $accountId,
        public ?int $tenantId = null,
        public ?int $createdBy = null,
        public ?string $idempotencyKey = null,
    ) {}
}
