<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('features')->updateOrInsert(
            ['code' => 'capital_management'],
            [
                'name' => 'Capital management',
                'description' => 'Manage tenant capital entries and linked accounting records.',
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        $featureId = DB::table('features')->where('code', 'capital_management')->value('id');

        if ($featureId === null) {
            return;
        }

        DB::table('packages')
            ->select('id')
            ->orderBy('id')
            ->each(function ($package) use ($featureId, $now): void {
                DB::table('package_features')->updateOrInsert(
                    [
                        'package_id' => $package->id,
                        'feature_id' => $featureId,
                    ],
                    [
                        'is_enabled' => true,
                        'value' => null,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            });
    }

    public function down(): void
    {
        $featureId = DB::table('features')->where('code', 'capital_management')->value('id');

        if ($featureId === null) {
            return;
        }

        DB::table('package_features')->where('feature_id', $featureId)->delete();
        DB::table('features')->where('id', $featureId)->delete();
    }
};
