<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedInteger('max_slip_per_month')->nullable()->after('price');
            $table->unsignedInteger('max_staff_count')->nullable()->after('max_slip_per_month');
        });

        Schema::table('tenant_licenses', function (Blueprint $table) {
            $table->unsignedInteger('current_month_slip_count')->default(0)->after('notes');
            $table->unsignedInteger('current_staff_count')->default(0)->after('current_month_slip_count');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_licenses', function (Blueprint $table) {
            $table->dropColumn(['current_month_slip_count', 'current_staff_count']);
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['max_slip_per_month', 'max_staff_count']);
        });
    }
};
