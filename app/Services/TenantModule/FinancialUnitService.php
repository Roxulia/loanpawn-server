<?php

namespace App\Services\TenantModule;

use App\DataObjects\ResponseObjects\FinancialUnitResource;
use App\Enums\FinancialUnit;
use App\Exceptions\InvalidTenantRequest;
use App\Services\BaseTenantService;
use App\Utility\MessageCode;

class FinancialUnitService extends BaseTenantService
{
    public function options(): array
    {
        return array_map(
            static fn (FinancialUnit $unit): array => FinancialUnitResource::fromEnum($unit)->toArray(),
            FinancialUnit::cases(),
        );
    }

    public function toBase(float|int|string $amount, ?string $unitCode, ?float $maximum = null): float
    {
        $unit = FinancialUnit::tryFrom($unitCode ?: FinancialUnit::Unit->value);

        if (! $unit) {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceFinancialUnitInvalid));
        }

        $normalized = (float) $amount * $unit->multiplier();

        if (! is_finite($normalized) || ($maximum !== null && abs($normalized) > $maximum)) {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceFinancialAmountTooLarge));
        }

        return $normalized;
    }
}
