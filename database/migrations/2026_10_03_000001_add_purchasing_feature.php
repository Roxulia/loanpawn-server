<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('features')->updateOrInsert(['code' => 'purchasing_management'], [
            'name' => 'Purchasing management',
            'description' => 'Record suppliers, purchases, receipts, payments, refunds, and returns.',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $featureId = DB::table('features')->where('code', 'purchasing_management')->value('id');
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
        $featureId = DB::table('features')->where('code', 'purchasing_management')->value('id');
        if ($featureId !== null) {
            DB::table('package_features')->where('feature_id', $featureId)->delete();
            DB::table('features')->where('id', $featureId)->delete();
        }
    }
};
