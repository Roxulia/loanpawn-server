<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class TenantSettingDetail extends BaseDataObject
{
    public array $items = [];
}

class TenantSettingItem extends BaseDataObject
{
    public int $updateKey;
    public string $key;
    public ?string $value;
    public ?string $category;
}
