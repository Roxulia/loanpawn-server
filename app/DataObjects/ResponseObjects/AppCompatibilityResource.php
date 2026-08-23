<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class AppCompatibilityResource extends BaseDataObject
{
    public function __construct(
        public readonly ?string $installedVersion,
        public readonly string $minimumSupportedVersion,
        public readonly bool $isSupported,
    ) {}
}
