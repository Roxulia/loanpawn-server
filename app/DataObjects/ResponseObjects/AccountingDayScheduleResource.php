<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class AccountingDayScheduleResource extends BaseDataObject
{
    public function __construct(public string $timezone, public array $days) {}
}
