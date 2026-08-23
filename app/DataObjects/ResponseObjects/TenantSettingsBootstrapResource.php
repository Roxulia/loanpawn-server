<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class TenantSettingsBootstrapResource extends BaseDataObject
{
    public function __construct(public array $sections) {}

    public function toArray(): array
    {
        return $this->transformValue($this->sections);
    }
}
