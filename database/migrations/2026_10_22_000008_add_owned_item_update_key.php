<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add optimistic concurrency metadata without changing historical quantities.
        Schema::table('owned_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('update_key')->default(0);
        });
    }

    public function down(): void
    {
        // Remove only the version column; preserve aggregates and ledger history.
        Schema::table('owned_items', fn (Blueprint $table) => $table->dropColumn('update_key'));
    }
};
