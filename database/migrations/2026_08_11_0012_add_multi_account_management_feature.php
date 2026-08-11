<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('features')->updateOrInsert(
            ['code' => 'multi_account_management'],
            [
                'name' => 'Multi-account management',
                'description' => 'Create, update, and manage multiple tenant financial accounts.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $featureId = DB::table('features')->where('code', 'multi_account_management')->value('id');

        foreach (['trial', 'basic', 'premium', 'budgeting-trial', 'budgeting-basic', 'budgeting-premium'] as $packageCode) {
            $packageId = DB::table('packages')->where('code', $packageCode)->value('id');
            if ($packageId !== null) {
                DB::table('package_features')->updateOrInsert(
                    ['package_id' => $packageId, 'feature_id' => $featureId],
                    ['is_enabled' => true, 'value' => null, 'created_at' => $now, 'updated_at' => $now]
                );
            }
        }

        DB::table('packages')->where('max_account_count', 0)->update(['max_account_count' => 1]);
    }

    public function down(): void
    {
        $featureId = DB::table('features')->where('code', 'multi_account_management')->value('id');
        if ($featureId !== null) {
            DB::table('package_features')->where('feature_id', $featureId)->delete();
            DB::table('features')->where('id', $featureId)->delete();
        }
    }
};
