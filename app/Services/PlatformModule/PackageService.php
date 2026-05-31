<?php

namespace App\Services\PlatformModule;

use App\Exceptions\InvalidTenantRequest;
use App\Models\PlatformModule\Package;
use App\Models\PlatformModule\PackageFeature;
use App\Repository\PackageRepository;
use App\Utility\MessageCodes;

class PackageService
{
    public function __construct(
        private PackageRepository $repository
    ) {
    }

    public function findActiveByCode(string $code): Package
    {
        $package = $this->repository->findActiveByCode($code);

        if (! $package) {
            throw new InvalidTenantRequest(MessageCodes::$messages['eb019']);
        }

        return $package;
    }

    public function planHasFeature(string $planType, string $featureCode): bool
    {
        return $this->findEnabledFeatureByPlan($planType, $featureCode) !== null;
    }

    public function findEnabledFeatureByPlan(string $planType, string $featureCode): ?PackageFeature
    {
        return $this->repository->findEnabledFeatureByPackageCode($planType, $featureCode);
    }

    public function featureValue(string $planType, string $featureCode): ?string
    {
        return $this->findEnabledFeatureByPlan($planType, $featureCode)?->value;
    }
}
