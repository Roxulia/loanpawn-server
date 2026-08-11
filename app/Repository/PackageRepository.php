<?php

namespace App\Repository;

use App\Models\PlatformModule\Feature;
use App\Models\PlatformModule\Package;
use App\Models\PlatformModule\PackageFeature;
use App\Models\PlatformModule\TenantCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PackageRepository
{
    public function findActiveByCode(string $code): ?Package
    {
        return Package::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->where('is_deleted', false)
            ->first();
    }

    public function findByCode(string $code): ?Package
    {
        return Package::query()
            ->where('code', $code)
            ->first();
    }

    public function findById(int $id): ?Package
    {
        return Package::query()->with('category')->find($id);
    }

    public function findActiveById(int $id): ?Package
    {
        return Package::query()
            ->with('category')
            ->whereKey($id)
            ->where('is_active', true)
            ->where('is_deleted', false)
            ->first();
    }

    public function trialForCategory(int $categoryId): ?Package
    {
        return Package::query()
            ->where('category_id', $categoryId)
            ->where('is_trial', true)
            ->where('is_active', true)
            ->where('is_deleted', false)
            ->first();
    }

    public function activeCategoriesWithPlans(): Collection
    {
        return TenantCategory::query()
            ->with(['packages' => fn ($query) => $query
                ->where('is_active', true)
                ->where('is_deleted', false)
                ->orderBy('rank')])
            ->where('is_active', true)
            ->where('is_deleted', false)
            ->orderBy('name')
            ->get();
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

    /**
     * @return array<string, array{code: string, is_active: bool, is_enabled: bool, unlock_in: array{code: string, name: string}|null}>
     */
    public function featureFlagsByPackageCode(string $packageCode): array
    {
        $features = Feature::query()
            ->orderBy('code')
            ->get(['id', 'code', 'is_active']);

        $enabledFeatureIds = PackageFeature::query()
            ->select('package_features.feature_id')
            ->join('packages', 'packages.id', '=', 'package_features.package_id')
            ->where('packages.code', $packageCode)
            ->whereIn('package_features.feature_id', $features->pluck('id'))
            ->where('package_features.is_enabled', true)
            ->pluck('package_features.feature_id')
            ->map(fn ($featureId) => (int) $featureId)
            ->all();

        $enabledFeatureIds = array_flip($enabledFeatureIds);

        $unlockPlans = PackageFeature::query()
            ->select([
                'package_features.feature_id',
                'packages.code as package_code',
                'packages.name as package_name',
            ])
            ->join('packages', 'packages.id', '=', 'package_features.package_id')
            ->where('packages.is_active', true)
            ->where('package_features.is_enabled', true)
            ->whereIn('package_features.feature_id', $features->pluck('id'))
            ->orderBy('packages.price')
            ->get()
            ->groupBy(fn ($row) => (int) $row->feature_id)
            ->map(fn (Collection $plans) => $plans->first());

        return $features
            ->mapWithKeys(fn (Feature $feature) => [
                $feature->code => [
                    'code' => $feature->code,
                    'is_active' => (bool) $feature->is_active,
                    'is_enabled' => array_key_exists((int) $feature->id, $enabledFeatureIds),
                    'unlock_in' => ($unlockPlan = $unlockPlans->get((int) $feature->id)) ? [
                        'code' => $unlockPlan->package_code,
                        'name' => $unlockPlan->package_name,
                    ] : null,
                ],
            ])
            ->all();
    }

    public function activePaidPackagesExcept(?string $excludedCode = null, ?int $categoryId = null): Collection
    {
        return Package::query()
            ->where('is_active', true)
            ->where('is_trial', false)
            ->where('is_deleted', false)
            ->when($categoryId, fn ($query) => $query->where('category_id', $categoryId))
            ->when($excludedCode, fn ($query) => $query->where('code', '<>', $excludedCode))
            ->orderBy('rank')
            ->get();
    }

    public function allWithFeatures(): Collection
    {
        return Package::query()
            ->with(['category', 'packageFeatures.feature'])
            ->where('is_deleted', false)
            ->orderBy('category_id')
            ->orderBy('rank')
            ->get();
    }

    public function allFeatures(): Collection
    {
        return Feature::query()->where('is_deleted', false)->orderBy('name')->get();
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

    public function updatePlanFlags(
        array $packageFlags,
        array $maxSlipPerMonth = [],
        array $maxStaffCount = [],
        array $maxAccountCount = [],
        array $maxCurrencyTypeCount = [],
        array $maxExchangePairCount = [],
    ): void {
        DB::transaction(function () use ($packageFlags, $maxSlipPerMonth, $maxStaffCount, $maxAccountCount, $maxCurrencyTypeCount, $maxExchangePairCount): void {
            foreach ($packageFlags as $packageId => $isActive) {
                Package::query()->whereKey($packageId)->update([
                    'is_active' => $isActive,
                    'max_slip_per_month' => $this->nullableIntegerValue($maxSlipPerMonth, $packageId),
                    'max_staff_count' => $this->nullableIntegerValue($maxStaffCount, $packageId),
                    'max_account_count' => $this->nullableIntegerValue($maxAccountCount, $packageId),
                    'max_currency_type_count' => $this->nullableIntegerValue($maxCurrencyTypeCount, $packageId),
                    'max_exchange_pair_count' => $this->nullableIntegerValue($maxExchangePairCount, $packageId),
                ]);
            }
        });
    }

    protected function nullableIntegerValue(array $values, int|string $key): ?int
    {
        if (! array_key_exists($key, $values) || $values[$key] === '' || $values[$key] === null) {
            return null;
        }

        return (int) $values[$key];
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
