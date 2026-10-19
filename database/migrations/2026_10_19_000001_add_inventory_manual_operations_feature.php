<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Register the deferred capability without granting it to existing packages.
        $now = now();
        DB::table('features')->updateOrInsert(['code' => 'inventory_manual_operations'], [
            'name' => 'Inventory manual operations',
            'description' => 'Receive, issue, and adjust stock outside business workflows.',
            'is_active' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Remove package links before removing this migration's feature registration.
        $featureId = DB::table('features')->where('code', 'inventory_manual_operations')->value('id');
        if ($featureId !== null) {
            DB::table('package_features')->where('feature_id', $featureId)->delete();
            DB::table('features')->where('id', $featureId)->delete();
        }
    }
};
