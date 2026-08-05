<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'email']);
            $table->dropUnique(['tenant_id', 'phone']);
            $table->dropUnique(['tenant_id', 'nrc']);
            $table->index(['tenant_id', 'email'], 'tenant_user_email_index');
            $table->index(['tenant_id', 'phone'], 'tenant_user_phone_index');
            $table->index(['tenant_id', 'nrc'], 'tenant_user_nrc_index');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropIndex('tenant_user_email_index');
            $table->dropIndex('tenant_user_phone_index');
            $table->dropIndex('tenant_user_nrc_index');
            $table->unique(['tenant_id', 'email']);
            $table->unique(['tenant_id', 'phone']);
            $table->unique(['tenant_id', 'nrc']);
        });
    }
};
