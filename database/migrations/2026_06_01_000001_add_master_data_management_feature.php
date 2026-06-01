<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('features')->updateOrInsert(
            ['code' => 'master_data_management'],
            [
                'name' => 'Master data management',
                'description' => 'Create and remove tenant material, interest, and expense types.',
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        $featureId = DB::table('features')->where('code', 'master_data_management')->value('id');

        foreach (['trial' => false, 'basic' => true, 'premium' => true] as $packageCode => $isEnabled) {
            $packageId = DB::table('packages')->where('code', $packageCode)->value('id');

            if ($packageId === null) {
                continue;
            }

            DB::table('package_features')->updateOrInsert(
                ['package_id' => $packageId, 'feature_id' => $featureId],
                [
                    'is_enabled' => $isEnabled,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        $featureId = DB::table('features')->where('code', 'master_data_management')->value('id');

        if ($featureId === null) {
            return;
        }

        DB::table('package_features')->where('feature_id', $featureId)->delete();
        DB::table('features')->where('id', $featureId)->delete();
    }
};
