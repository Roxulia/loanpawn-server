<?php

namespace App\Services\PlatformModule;

use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\TenantNotFound;
use App\Models\PlatformModule\Package;
use App\Models\PlatformModule\PackageFeature;
use App\Repository\PackageRepository;
use App\Utility\MessageCode;
use App\Utility\Messages;
use Illuminate\Support\Collection;

class PackageService
{
    public function __construct(
        private PackageRepository $repository,
        private Messages $messages
    ) {}

    public function findActiveByCode(string $code): Package
    {
        $package = $this->repository->findActiveByCode($code);

        if (! $package) {
            throw new TenantNotFound($this->messages->responseMessage(MessageCode::PackageNotFound));
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

    /**
     * @return array<string, array{code: string, is_active: bool, is_enabled: bool, unlock_in: array{code: string, name: string}|null}>
     */
    public function featureFlagsByPlan(string $planType): array
    {
        return $this->repository->featureFlagsByPackageCode($planType);
    }

    public function activePaidPackagesExcept(?string $excludedCode = null): Collection
    {
        return $this->repository->activePaidPackagesExcept($excludedCode);
    }

    public function flagMatrix(): array
    {
        return [
            'features' => $this->repository->allFeatures(),
            'packages' => $this->repository->allWithFeatures(),
        ];
    }

    public function updateFlags(array $featureFlags, array $packageFlags, array $mappingFlags): void
    {
        $this->repository->updateFlags($featureFlags, $packageFlags, $mappingFlags);
    }

    public function updatePlanFlags(array $packageFlags): void
    {
        $this->repository->updatePlanFlags($packageFlags);
    }

    public function createFeature(array $data): void
    {
        $this->repository->createFeature($data);
    }

    public function updateFeatureFlags(array $featureFlags): void
    {
        $this->repository->updateFeatureFlags($featureFlags);
    }

    public function updateFeatureAssignments(array $assignmentFlags): void
    {
        $this->repository->updateFeatureAssignments($assignmentFlags);
    }
}
