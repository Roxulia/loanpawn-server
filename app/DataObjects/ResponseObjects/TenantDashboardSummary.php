<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class TenantDashboardSummary extends BaseDataObject
{
    public array $filters;
    public array $financial;
    public array $collateral;
    public array $loans;
    public array $customers;
    public array $expenses;

    public function __construct(
        array $filters,
        array $financial,
        array $collateral,
        array $loans,
        array $customers,
        array $expenses,
    ) {
        $this->filters = $filters;
        $this->financial = $financial;
        $this->collateral = $collateral;
        $this->loans = $loans;
        $this->customers = $customers;
        $this->expenses = $expenses;
    }
}
