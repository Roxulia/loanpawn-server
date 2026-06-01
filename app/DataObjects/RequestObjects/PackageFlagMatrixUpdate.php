<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class PackageFlagMatrixUpdate extends BaseDataObject
{
    public function __construct(
        public array $featureFlags,
        public array $packageFlags,
        public array $mappingFlags,
    ) {
    }
}
