<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class AccountingDayScheduleUpdate extends BaseDataObject
{
    public function __construct(public array $days) {}
}
