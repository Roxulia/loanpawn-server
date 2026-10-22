<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Insert only a missing registration; retain existing administrator settings and package grants.
        if (DB::table('features')->where('code', 'ownership_manual_acquisition')->exists()) {
            return;
        }

        // Register the deferred capability as inactive without assigning it to any package.
        $now = now();
        DB::table('features')->insert([
            'code' => 'ownership_manual_acquisition',
            'name' => 'Ownership manual acquisition',
            'description' => 'Record ownership acquisitions outside business workflows.',
            'is_active' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Retain the registration: rollback cannot identify pre-existing rows or later administrator grants.
    }
};
