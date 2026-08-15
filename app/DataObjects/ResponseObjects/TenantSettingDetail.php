<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class TenantSettingDetail extends BaseDataObject
{
    public array $items = [];
    public ?int $default_currency_id = null;
    public ?int $reporting_currency_id = null;
    public ?int $effective_reporting_currency_id = null;
    public ?string $default_currency_symbol = null;
    public ?string $reporting_currency_symbol = null;
    public ?string $effective_reporting_currency_symbol = null;
    public ?array $reporting_currency_recalculation = null;
}

class TenantSettingItem extends BaseDataObject
{
    public int $updateKey;
    public string $key;
    public ?string $value;
    public ?string $category;
}
