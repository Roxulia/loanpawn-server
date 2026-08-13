<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['tenant_roles', 'tenant_user_permissions'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->boolean('manage_tenant_timezone')->default(false));
        }
        DB::table('tenant_roles')->whereRaw('LOWER(name) = ?', ['admin'])->update(['manage_tenant_timezone' => true]);
        $adminIds = DB::table('tenant_users')->join('tenant_roles', 'tenant_roles.id', '=', 'tenant_users.role_id')->whereRaw('LOWER(tenant_roles.name) = ?', ['admin'])->pluck('tenant_users.id');
        DB::table('tenant_user_permissions')->whereIn('tenant_user_id', $adminIds)->update(['manage_tenant_timezone' => true]);

        DB::table('features')->updateOrInsert(['code' => 'tenant_timezone_management'], [
            'name' => 'Tenant timezone management',
            'description' => 'Configure the tenant business timezone.',
            'is_active' => true,
            'is_deleted' => false,
            'update_key' => 0,
            'updated_at' => now(),
        ]);
        $featureId = DB::table('features')->where('code', 'tenant_timezone_management')->value('id');
        $premiumId = DB::table('packages')->where('code', 'premium')->value('id');
        if ($premiumId) {
            DB::table('package_features')->updateOrInsert([
                'package_id' => $premiumId, 'feature_id' => $featureId,
            ], [
                'is_enabled' => true,
                'is_deleted' => false,
                'update_key' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $featureId = DB::table('features')->where('code', 'tenant_timezone_management')->value('id');
        if ($featureId) DB::table('package_features')->where('feature_id', $featureId)->delete();
        DB::table('features')->where('code', 'tenant_timezone_management')->delete();
        foreach (['tenant_roles', 'tenant_user_permissions'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('manage_tenant_timezone'));
        }
    }
};
