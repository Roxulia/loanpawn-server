<?php

namespace App\Repository;

use App\Models\PlatformModule\Package;
use App\Models\PlatformModule\PackageFeature;

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
            ->where('packages.is_active', true)
            ->where('features.code', $featureCode)
            ->where('package_features.is_enabled', true)
            ->first();
    }
}
