<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'list_capital',
        'create_capital',
        'update_capital',
        'delete_capital',
    ];

    public function up(): void
    {
        Schema::table('tenant_roles', function (Blueprint $table) {
            foreach (self::PERMISSIONS as $permission) {
                if (! Schema::hasColumn('tenant_roles', $permission)) {
                    $table->boolean($permission)->default(false);
                }
            }
        });

        Schema::table('tenant_user_permissions', function (Blueprint $table) {
            foreach (self::PERMISSIONS as $permission) {
                if (! Schema::hasColumn('tenant_user_permissions', $permission)) {
                    $table->boolean($permission)->default(false);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_roles', function (Blueprint $table) {
            foreach (array_reverse(self::PERMISSIONS) as $permission) {
                if (Schema::hasColumn('tenant_roles', $permission)) {
                    $table->dropColumn($permission);
                }
            }
        });

        Schema::table('tenant_user_permissions', function (Blueprint $table) {
            foreach (array_reverse(self::PERMISSIONS) as $permission) {
                if (Schema::hasColumn('tenant_user_permissions', $permission)) {
                    $table->dropColumn($permission);
                }
            }
        });
    }
};
