<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Register Ownership as an independently assignable tenant feature.
        $now = now();
        DB::table('features')->updateOrInsert(['code' => 'ownership_management'], [
            'name' => 'Ownership management',
            'description' => 'Track tenant owned items, acquisition lots, and ownership changes.',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Enable the feature for paid packages, following the Inventory package defaults.
        $featureId = DB::table('features')->where('code', 'ownership_management')->value('id');
        foreach (['trial' => false, 'basic' => true, 'premium' => true] as $code => $enabled) {
            $packageId = DB::table('packages')->where('code', $code)->value('id');
            if ($packageId !== null) {
                DB::table('package_features')->updateOrInsert(
                    ['package_id' => $packageId, 'feature_id' => $featureId],
                    ['is_enabled' => $enabled, 'created_at' => $now, 'updated_at' => $now]
                );
            }
        }
    }

    public function down(): void
    {
        // Remove only this feature registration and its package assignments.
        $featureId = DB::table('features')->where('code', 'ownership_management')->value('id');
        if ($featureId !== null) {
            DB::table('package_features')->where('feature_id', $featureId)->delete();
            DB::table('features')->where('id', $featureId)->delete();
        }
    }
};
