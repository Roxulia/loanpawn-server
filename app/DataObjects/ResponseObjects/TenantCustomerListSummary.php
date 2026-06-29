<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class TenantCustomerListSummary extends BaseDataObject
{
    public int $totalClients;
    public float $averageTrustScore;
    public int $activePawnLoans;
    public int $riskFlagged;

    public function __construct(
        int $totalClients,
        float $averageTrustScore,
        int $activePawnLoans,
        int $riskFlagged,
    ) {
        $this->totalClients = $totalClients;
        $this->averageTrustScore = $averageTrustScore;
        $this->activePawnLoans = $activePawnLoans;
        $this->riskFlagged = $riskFlagged;
    }
}
