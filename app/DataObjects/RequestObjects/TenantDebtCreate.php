<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantDebtCreate extends BaseDataObject
{
    public function __construct(
        public float $amount,
        public string $description,
        public ?int $slipId = null,
        public ?string $tag = null,
        public bool $isPaid = false,
        public ?int $acceptedBy = null,
        public ?int $tenantId = null,
        public ?int $createdBy = null,
        public ?string $idempotencyKey = null,
        public ?bool $internalOperation = false,
    ) {
    }
}
