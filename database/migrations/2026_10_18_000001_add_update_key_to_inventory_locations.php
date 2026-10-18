<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Initialize existing and new locations with an optimistic concurrency version.
        Schema::table('inventory_locations', function (Blueprint $table): void {
            $table->unsignedInteger('update_key')->default(0);
        });
    }

    public function down(): void
    {
        // Reverse only the version column introduced by this migration.
        Schema::table('inventory_locations', function (Blueprint $table): void {
            $table->dropColumn('update_key');
        });
    }
};
