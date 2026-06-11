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
        Schema::table('tenant_customers', function (Blueprint $table) {
            $table->string('nrc')->nullable()->after('code');

            $table->index(['tenant_id','nrc'],'customer_nrc_index');
            $table->unique(['tenant_id','nrc'],'customer_nrc_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_customers', function (Blueprint $table) {
            //
            $table->dropIndex('customer_nrc_index');
            $table->dropUnique('customer_nrc_unique');
            $table->removeColumn('nrc');
        });
    }
};
