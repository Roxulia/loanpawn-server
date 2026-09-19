<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Register the capability independently so package access can be changed
        // without changing the scheduled-expense database schema.
        DB::table('features')->updateOrInsert(['code' => 'scheduled_expense_management'], [
            'name' => 'Scheduled expense management',
            'description' => 'Configure and automatically pay one-time and recurring expenses.',
            'is_active' => true,
            'is_deleted' => false,
            'update_key' => 0,
            'updated_at' => now(),
        ]);

        $featureId = DB::table('features')
            ->where('code', 'scheduled_expense_management')
            ->value('id');

        // Scheduled automation is a Premium capability by default.
        foreach (DB::table('packages')->where('code', 'premium')->pluck('id') as $premiumId) {
            DB::table('package_features')->updateOrInsert(
                ['package_id' => $premiumId, 'feature_id' => $featureId],
                [
                    'is_enabled' => true,
                    'is_deleted' => false,
                    'update_key' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        $featureId = DB::table('features')
            ->where('code', 'scheduled_expense_management')
            ->value('id');

        if ($featureId !== null) {
            DB::table('package_features')->where('feature_id', $featureId)->delete();
        }

        DB::table('features')->where('code', 'scheduled_expense_management')->delete();
    }
};
