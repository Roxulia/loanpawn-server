<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_expenses', function (Blueprint $table): void {
            $table->string('image_reference')->nullable()->after('expense_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_expenses', function (Blueprint $table): void {
            $table->dropColumn('image_reference');
        });
    }
};
