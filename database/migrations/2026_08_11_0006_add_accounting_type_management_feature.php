<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('features')->updateOrInsert(
            ['code' => 'accounting_type_management'],
            ['name' => 'Accounting type management', 'description' => 'Manage tenant-owned financial account types.', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]
        );
        $featureId = DB::table('features')->where('code', 'accounting_type_management')->value('id');

        foreach (['budgeting-trial', 'budgeting-basic', 'budgeting-premium'] as $packageCode) {
            $packageId = DB::table('packages')->where('code', $packageCode)->value('id');
            if ($packageId !== null) {
                DB::table('package_features')->updateOrInsert(
                    ['package_id' => $packageId, 'feature_id' => $featureId],
                    ['is_enabled' => true, 'value' => null, 'created_at' => $now, 'updated_at' => $now]
                );
            }
        }
    }

    public function down(): void
    {
        $featureId = DB::table('features')->where('code', 'accounting_type_management')->value('id');
        if ($featureId !== null) {
            DB::table('package_features')->where('feature_id', $featureId)->delete();
            DB::table('features')->where('id', $featureId)->delete();
        }
    }
};
