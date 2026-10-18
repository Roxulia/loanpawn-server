<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Initialize existing and new items with a stock and reservation version.
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->unsignedInteger('update_key')->default(0);
        });
    }

    public function down(): void
    {
        // Reverse only the version column introduced by this migration.
        Schema::table('inventory_items', function (Blueprint $table): void {
            $table->dropColumn('update_key');
        });
    }
};
