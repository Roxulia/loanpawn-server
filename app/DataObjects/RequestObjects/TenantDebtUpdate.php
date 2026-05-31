<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantDebtUpdate extends BaseDataObject
{
    public function __construct(
        public int $debtId,
        public string $code,
        public int $updateKey,
        public ?float $amount = null,
        public ?string $description = null,
        public ?int $slipId = null,
        public ?string $tag = null,
        public ?bool $isPaid = null,
        public ?int $acceptedBy = null,
    ) {
    }
}
