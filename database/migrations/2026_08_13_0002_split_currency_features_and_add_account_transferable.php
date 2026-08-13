<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $legacyFeature = DB::table('features')->where('code', 'currency_exchange_management')->first();
            $featureDefinitions = [
                'currency_management' => ['Currency management', 'Manage tenant currencies and currency preferences.'],
                'exchange_pair_management' => ['Exchange pair management', 'Manage tenant exchange-rate pairs.'],
                'daily_rate_assignment' => ['Daily rate assignment', 'Assign exchange rates and view daily rate history and trends.'],
                'account_transferable' => ['Account transferable', 'Transfer balances between tenant financial accounts.'],
            ];

            foreach ($featureDefinitions as $code => [$name, $description]) {
                DB::table('features')->updateOrInsert(
                    ['code' => $code],
                    [
                        'name' => $name,
                        'description' => $description,
                        'is_active' => $code === 'account_transferable' ? true : (bool) ($legacyFeature->is_active ?? true),
                        'is_deleted' => false,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }

            if ($legacyFeature !== null) {
                $legacyMappings = DB::table('package_features')->where('feature_id', $legacyFeature->id)->get();
                foreach (['currency_management', 'exchange_pair_management', 'daily_rate_assignment'] as $featureCode) {
                    $featureId = DB::table('features')->where('code', $featureCode)->value('id');
                    foreach ($legacyMappings as $mapping) {
                        DB::table('package_features')->updateOrInsert(
                            ['package_id' => $mapping->package_id, 'feature_id' => $featureId],
                            [
                                'is_enabled' => $mapping->is_enabled,
                                'value' => $mapping->value,
                                'is_deleted' => false,
                                'updated_at' => $now,
                                'created_at' => $now,
                            ]
                        );
                    }
                }

                DB::table('package_features')->where('feature_id', $legacyFeature->id)->delete();
                DB::table('features')->where('id', $legacyFeature->id)->delete();
            }

            $multiAccountFeatureId = DB::table('features')->where('code', 'multi_account_management')->value('id');
            $accountTransferableFeatureId = DB::table('features')->where('code', 'account_transferable')->value('id');
            if ($multiAccountFeatureId !== null && $accountTransferableFeatureId !== null) {
                foreach (DB::table('package_features')->where('feature_id', $multiAccountFeatureId)->get() as $mapping) {
                    DB::table('package_features')->updateOrInsert(
                        ['package_id' => $mapping->package_id, 'feature_id' => $accountTransferableFeatureId],
                        [
                            'is_enabled' => $mapping->is_enabled,
                            'value' => $mapping->value,
                            'is_deleted' => false,
                            'updated_at' => $now,
                            'created_at' => $now,
                        ]
                    );
                }
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $splitFeatures = DB::table('features')
                ->whereIn('code', ['currency_management', 'exchange_pair_management', 'daily_rate_assignment'])
                ->get()
                ->keyBy('code');

            DB::table('features')->updateOrInsert(
                ['code' => 'currency_exchange_management'],
                [
                    'name' => 'Currency and exchange-rate management',
                    'description' => 'Manage tenant currencies, exchange pairs, rates, and daily OHLC history.',
                    'is_active' => $splitFeatures->count() === 3 && $splitFeatures->every(fn ($feature): bool => (bool) $feature->is_active),
                    'is_deleted' => false,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            $legacyFeatureId = DB::table('features')->where('code', 'currency_exchange_management')->value('id');
            $splitFeatureIds = $splitFeatures->pluck('id');
            $packageIds = DB::table('package_features')->whereIn('feature_id', $splitFeatureIds)->pluck('package_id')->unique();
            foreach ($packageIds as $packageId) {
                $mappings = DB::table('package_features')
                    ->where('package_id', $packageId)
                    ->whereIn('feature_id', $splitFeatureIds)
                    ->get();
                DB::table('package_features')->updateOrInsert(
                    ['package_id' => $packageId, 'feature_id' => $legacyFeatureId],
                    [
                        'is_enabled' => $mappings->count() === 3 && $mappings->every(fn ($mapping): bool => (bool) $mapping->is_enabled),
                        'value' => null,
                        'is_deleted' => false,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }

            $featureIdsToDelete = DB::table('features')
                ->whereIn('code', ['currency_management', 'exchange_pair_management', 'daily_rate_assignment', 'account_transferable'])
                ->pluck('id');
            DB::table('package_features')->whereIn('feature_id', $featureIdsToDelete)->delete();
            DB::table('features')->whereIn('id', $featureIdsToDelete)->delete();
        });
    }
};
