<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class AppVersionRequest extends BaseDataObject
{
    public function __construct(public readonly ?string $installedVersion) {}
}
