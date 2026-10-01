<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('features')->updateOrInsert(['code' => 'catalog_management'], [
            'name' => 'Catalog management',
            'description' => 'Search and manage reusable catalog items, categories, and units.',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $featureId = DB::table('features')->where('code', 'catalog_management')->value('id');
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
        $id = DB::table('features')->where('code', 'catalog_management')->value('id');
        if ($id !== null) {
            DB::table('package_features')->where('feature_id', $id)->delete();
            DB::table('features')->where('id', $id)->delete();
        }
    }
};
