<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('platform_users', function (Blueprint $table): void {
            $table->string('prefer_lang', 8)->default('mm')->change();
        });

        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->string('prefer_lang', 8)->default('mm')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_users', function (Blueprint $table): void {
            $table->string('prefer_lang', 8)->default('en')->change();
        });

        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->string('prefer_lang', 8)->default('en')->change();
        });
    }
};
