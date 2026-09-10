<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FEATURES = [
        'business_loan_management' => ['Business Loan Management', 'Manage tenant business loans and lender repayments.'],
        'lender_management' => ['Lender Management', 'Manage tenant lender identity records.'],
    ];

    public function up(): void
    {
        // Registration of feature gates and package mappings
        foreach (self::FEATURES as $code => [$name, $description]) {
            DB::table('features')->updateOrInsert(['code' => $code], [
                'name' => $name,
                'description' => $description,
                'is_active' => true,
                'is_deleted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Copying package availability from debt management
            $featureId = DB::table('features')->where('code', $code)->value('id');
            $debtFeatureId = DB::table('features')->where('code', 'debt_management')->value('id');

            foreach (DB::table('package_features')->where('feature_id', $debtFeatureId)->get() as $mapping) {
                DB::table('package_features')->updateOrInsert(
                    ['package_id' => $mapping->package_id, 'feature_id' => $featureId],
                    ['is_enabled' => $mapping->is_enabled, 'value' => null, 'is_deleted' => false, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        // Removal of feature gates and package mappings
        $featureIds = DB::table('features')->whereIn('code', array_keys(self::FEATURES))->pluck('id');

        DB::table('package_features')->whereIn('feature_id', $featureIds)->delete();
        DB::table('features')->whereIn('id', $featureIds)->delete();
    }
};
