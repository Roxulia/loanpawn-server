<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenant_roles', function (Blueprint $table) {
            $table->boolean('dashboard')->default(false)->after('is_deleted');
        });

        Schema::table('tenant_user_permissions', function (Blueprint $table) {
            $table->boolean('dashboard')->default(false)->after('tenant_user_id');
        });

        DB::table('tenant_roles')
            ->where('name', 'Owner')
            ->update(['dashboard' => true]);

        DB::table('tenant_user_permissions')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('tenant_users')
                    ->join('tenant_roles', 'tenant_roles.id', '=', 'tenant_users.role_id')
                    ->whereColumn('tenant_users.id', 'tenant_user_permissions.tenant_user_id')
                    ->whereColumn('tenant_users.tenant_id', 'tenant_user_permissions.tenant_id')
                    ->where('tenant_roles.name', 'Owner');
            })
            ->update(['dashboard' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_user_permissions', function (Blueprint $table) {
            $table->dropColumn('dashboard');
        });

        Schema::table('tenant_roles', function (Blueprint $table) {
            $table->dropColumn('dashboard');
        });
    }
};
