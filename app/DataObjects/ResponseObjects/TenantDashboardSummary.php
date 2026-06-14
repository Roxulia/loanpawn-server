<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class TenantDashboardSummary extends BaseDataObject
{
    public array $financial;
    public array $collateral;
    public array $loans;
    public array $customers;
    public array $expenses;

    public function __construct(
        array $financial,
        array $collateral,
        array $loans,
        array $customers,
        array $expenses,
    ) {
        $this->financial = $financial;
        $this->collateral = $collateral;
        $this->loans = $loans;
        $this->customers = $customers;
        $this->expenses = $expenses;
    }
}
