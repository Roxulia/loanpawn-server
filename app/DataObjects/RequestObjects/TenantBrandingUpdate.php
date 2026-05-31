<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantBrandingUpdate extends BaseDataObject
{
    public function __construct(
        public int $updateKey,
        public ?string $primaryColor = null,
        public ?string $secondaryColor = null,
        public ?string $accentColor = null,
    ) {
    }
}
