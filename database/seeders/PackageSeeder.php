<?php

namespace Database\Seeders;

use App\Models\PlatformModule\Feature;
use App\Models\PlatformModule\Package;
use App\Models\PlatformModule\PackageFeature;
use App\Models\PlatformModule\TenantCategory;
use Illuminate\Database\Seeder;

class PackageSeeder extends Seeder
{
    public function run(): void
    {
        $pawnCategory = TenantCategory::query()->updateOrCreate(
            ['code' => 'pawn-shop'],
            ['name' => 'Pawn Shop', 'description' => 'Pawn shop operations, customers, collateral, loans, and accounting.', 'is_active' => true]
        );
        $budgetingCategory = TenantCategory::query()->updateOrCreate(
            ['code' => 'budgeting'],
            ['name' => 'Budgeting', 'description' => 'Accounting, expenses, capital, and debt management.', 'is_active' => true]
        );
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

        foreach (config('package_features.packages') as $index => $packageDefinition) {
            $code = (string) $index;
            $package = Package::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $packageDefinition['name'],
                    'category_id' => $pawnCategory->id,
                    'rank' => match ($code) {
                        'trial' => 0, 'basic' => 100, default => 200
                    },
                    'is_trial' => $code === 'trial',
                    'description' => $packageDefinition['description'] ?? null,
                    'price' => $packageDefinition['price'],
                    'max_slip_per_month' => $packageDefinition['max_slip_per_month'] ?? null,
                    'max_staff_count' => $packageDefinition['max_staff_count'] ?? null,
                    'max_account_count' => $packageDefinition['max_account_count'] ?? null,
                    'max_currency_type_count' => $packageDefinition['max_currency_type_count'] ?? null,
                    'max_exchange_pair_count' => $packageDefinition['max_exchange_pair_count'] ?? null,
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

        foreach (config('package_features.budgeting_packages') as $code => $definition) {
            $package = Package::query()->updateOrCreate(
                ['code' => $code],
                [
                    'category_id' => $budgetingCategory->id,
                    'rank' => $definition['rank'],
                    'is_trial' => $definition['is_trial'],
                    'name' => $definition['name'],
                    'description' => $definition['description'] ?? null,
                    'price' => $definition['price'],
                    'max_slip_per_month' => $definition['max_slip_per_month'] ?? null,
                    'max_staff_count' => $definition['max_staff_count'] ?? null,
                    'max_account_count' => $definition['max_account_count'] ?? null,
                    'max_currency_type_count' => $definition['max_currency_type_count'] ?? null,
                    'max_exchange_pair_count' => $definition['max_exchange_pair_count'] ?? null,
                    'is_active' => $definition['is_active'],
                ]
            );

            foreach ($features as $featureCode => $feature) {
                PackageFeature::query()->updateOrCreate(
                    ['package_id' => $package->id, 'feature_id' => $feature->id],
                    [
                        'is_enabled' => in_array($featureCode, [
                            'accounting_management',
                            'expense_management',
                            'capital_management',
                            'debt_management',
                            'accounting_type_management',
                            'multi_account_management',
                            'account_transferable',
                        ], true),
                        'value' => null,
                    ]
                );
            }
        }
    }
}
