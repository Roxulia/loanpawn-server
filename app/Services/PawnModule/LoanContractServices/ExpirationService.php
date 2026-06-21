<?php

namespace App\Services\PawnModule\LoanContractServices;

use App\Repository\LoanContractSlipRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class ExpirationService
{
    public function __construct(
        private LoanContractSlipRepository $repository,
    ) {
    }

    public function checkExpire(?CarbonInterface $currentDate = null): int
    {
        $currentDate = $currentDate === null
            ? CarbonImmutable::now()->startOfDay()
            : CarbonImmutable::parse($currentDate)->startOfDay();

        return $this->repository->expireOverdueActiveSlips($currentDate);
    }
}
