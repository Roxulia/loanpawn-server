<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class FinancialAccountAssignmentUpdate extends BaseDataObject
{
    public function __construct(public array $financialAccountIds) {}
}
