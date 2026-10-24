<?php

namespace App\Services\TenantModule\Ownership;

use App\Exceptions\InvalidTenantRequest;
use App\Repository\SalesRepository;
use App\Services\BaseTenantService;

/** Sale module source validation without a circular dependency on full checkout orchestration. */
class SaleSourceService extends BaseTenantService
{
    public function __construct(private SalesRepository $repository) {}

    public function line(string $code)
    {
        // A code alone grants no authority: require a same-tenant confirmed order and its real line.
        $line = $this->repository->ownershipLine($this->resolveCurrentTenantId(), $code);
        if ($line === null || $line->order === null || (int) $line->order->tenant_id !== $this->resolveCurrentTenantId()
            || ! in_array($line->order->status, ['CONFIRMED', 'COMPLETED'], true)) {
            throw new InvalidTenantRequest(__('ownership.commitment_source_invalid'));
        }
        return $line;
    }

    public function returnLine(string $code)
    {
        // Resolve actual stored source authority without entering full Sales orchestration.
        return $this->repository->ownershipReturnLine($this->resolveCurrentTenantId(), $code)
            ?? throw new InvalidTenantRequest(__('ownership.commitment_source_invalid'));
    }
}
