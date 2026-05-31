<?php

namespace Database\Seeders;

use App\Models\PlatformModule\Feature;
use App\Models\PlatformModule\Package;
use App\Models\PlatformModule\PackageFeature;
use Illuminate\Database\Seeder;

class PackageSeeder extends Seeder
{
    public function run(): void
    {
        $features = [];

        foreach (config('package_features.features') as $code => $feature) {
            $features[$code] = Feature::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $feature['name'],
                    'description' => $feature['description'] ?? null,
                ]
            );
        }

        foreach (config('package_features.packages') as $code => $packageDefinition) {
            $package = Package::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $packageDefinition['name'],
                    'description' => $packageDefinition['description'] ?? null,
                    'price' => $packageDefinition['price'],
                    'is_active' => $packageDefinition['is_active'],
                ]
            );

            $enabledFeatureCodes = $packageDefinition['features'] ?? [];

            foreach ($features as $featureCode => $feature) {
                PackageFeature::query()->updateOrCreate(
                    [
                        'package_id' => $package->id,
                        'feature_id' => $feature->id,
                    ],
                    [
                        'is_enabled' => in_array($featureCode, $enabledFeatureCodes, true),
                        'value' => $packageDefinition['feature_values'][$featureCode] ?? null,
                    ]
                );
            }
        }
    }
}
