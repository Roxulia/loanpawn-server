<?php

namespace App\Repository;

use App\Models\PlatformModule\Feature;
use App\Models\PlatformModule\Package;
use App\Models\PlatformModule\PackageFeature;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PackageRepository
{
    public function findActiveByCode(string $code): ?Package
    {
        return Package::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->first();
    }

    public function findEnabledFeatureByPackageCode(string $packageCode, string $featureCode): ?PackageFeature
    {
        return PackageFeature::query()
            ->select('package_features.*')
            ->join('packages', 'packages.id', '=', 'package_features.package_id')
            ->join('features', 'features.id', '=', 'package_features.feature_id')
            ->where('packages.code', $packageCode)
            ->where('features.code', $featureCode)
            ->where('features.is_active', true)
            ->where('package_features.is_enabled', true)
            ->first();
    }

    public function activePaidPackagesExcept(?string $excludedCode = null): Collection
    {
        return Package::query()
            ->where('is_active', true)
            ->where('code', '<>', 'trial')
            ->when($excludedCode, fn ($query) => $query->where('code', '<>', $excludedCode))
            ->orderBy('price')
            ->get();
    }

    public function allWithFeatures(): Collection
    {
        return Package::query()
            ->with(['packageFeatures.feature'])
            ->orderBy('price')
            ->get();
    }

    public function allFeatures(): Collection
    {
        return Feature::query()->orderBy('name')->get();
    }

    public function updateFlags(array $featureFlags, array $packageFlags, array $mappingFlags): void
    {
        DB::transaction(function () use ($featureFlags, $packageFlags, $mappingFlags): void {
            foreach ($featureFlags as $featureId => $isActive) {
                Feature::query()->whereKey($featureId)->update(['is_active' => $isActive]);
            }

            foreach ($packageFlags as $packageId => $isActive) {
                Package::query()->whereKey($packageId)->update(['is_active' => $isActive]);
            }

            foreach ($mappingFlags as $packageFeatureId => $isEnabled) {
                PackageFeature::query()->whereKey($packageFeatureId)->update(['is_enabled' => $isEnabled]);
            }
        });
    }

    public function updatePlanFlags(array $packageFlags): void
    {
        DB::transaction(function () use ($packageFlags): void {
            foreach ($packageFlags as $packageId => $isActive) {
                Package::query()->whereKey($packageId)->update(['is_active' => $isActive]);
            }
        });
    }

    public function createFeature(array $data): Feature
    {
        return DB::transaction(function () use ($data): Feature {
            $feature = Feature::query()->create([
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => true,
            ]);

            Package::query()->select('id')->each(function (Package $package) use ($feature): void {
                PackageFeature::query()->updateOrCreate(
                    [
                        'package_id' => $package->id,
                        'feature_id' => $feature->id,
                    ],
                    ['is_enabled' => false]
                );
            });

            return $feature;
        });
    }

    public function updateFeatureFlags(array $featureFlags): void
    {
        DB::transaction(function () use ($featureFlags): void {
            foreach ($featureFlags as $featureId => $isActive) {
                Feature::query()->whereKey($featureId)->update(['is_active' => $isActive]);
            }
        });
    }

    public function updateFeatureAssignments(array $assignmentFlags): void
    {
        DB::transaction(function () use ($assignmentFlags): void {
            foreach ($assignmentFlags as $packageId => $featureFlags) {
                foreach ($featureFlags as $featureId => $isEnabled) {
                    PackageFeature::query()->updateOrCreate(
                        [
                            'package_id' => $packageId,
                            'feature_id' => $featureId,
                        ],
                        ['is_enabled' => $isEnabled]
                    );
                }
            }
        });
    }
}
