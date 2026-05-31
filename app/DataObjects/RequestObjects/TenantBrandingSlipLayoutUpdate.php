<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantBrandingSlipLayoutUpdate extends BaseDataObject
{
    public function __construct(
        public ?array $slipHeaderLayout = null,
        public ?array $slipFooterLayout = null,
        public int $updateKey
    ) {
    }
}
