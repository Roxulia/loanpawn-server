<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantDebtPaymentPolicyUpdate extends BaseDataObject
{
    public function __construct(
        public bool $allowPartialPayments,
        public int $updateKey,
    ) {}
}
