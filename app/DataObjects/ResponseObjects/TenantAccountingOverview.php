<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class TenantAccountingOverview extends BaseDataObject
{
    public function __construct(
        public float $liquidCapital,
        public float $monthIncoming,
        public float $monthOutgoing,
        public float $incomingProgress,
        public float $outgoingProgress,
    ) {
    }
}
