<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class InterestProcessSettingsUpdate extends BaseDataObject
{
    public function __construct(
        public bool $compoundingEnabled,
        public bool $partialPrincipalCollectionEnabled,
        public int $updateKey,
    ) {}
}
