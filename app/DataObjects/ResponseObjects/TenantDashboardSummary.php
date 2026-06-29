<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class TenantDashboardSummary extends BaseDataObject
{
    public array $filters;
    public array $financial;
    public array $risk;
    public array $collateral;

    public function __construct(
        array $filters,
        array $financial,
        array $risk,
        array $collateral,
    ) {
        $this->filters = $filters;
        $this->financial = $financial;
        $this->risk = $risk;
        $this->collateral = $collateral;
    }
}
