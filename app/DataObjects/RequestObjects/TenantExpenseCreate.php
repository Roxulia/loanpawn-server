<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantExpenseCreate extends BaseDataObject
{
    public function __construct(
        public string $description,
        public float $amount,
        public ?int $expenseTypeId = null,
        public ?int $tenantId = null,
        public ?int $createdBy = null,
        public ?string $idempotencyKey = null,
    ) {
    }
}
