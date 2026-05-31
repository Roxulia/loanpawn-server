<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantExpenseUpdate extends BaseDataObject
{
    public function __construct(
        public int $expenseId,
        public string $code,
        public int $updateKey,
        public ?string $description = null,
        public ?float $amount = null,
        public ?int $expenseTypeId = null,
    ) {
    }
}
