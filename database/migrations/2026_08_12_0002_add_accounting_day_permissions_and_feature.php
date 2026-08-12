<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = ['open_accounting_day', 'close_accounting_day'];

    public function up(): void
    {
        foreach (['tenant_roles', 'tenant_user_permissions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                foreach (self::PERMISSIONS as $permission) {
                    $table->boolean($permission)->default(false);
                }
            });
        }

        DB::table('tenant_roles')->whereRaw('LOWER(name) = ?', ['admin'])->update(array_fill_keys(self::PERMISSIONS, true));
        $adminIds = DB::table('tenant_users')
            ->join('tenant_roles', 'tenant_roles.id', '=', 'tenant_users.role_id')
            ->whereRaw('LOWER(tenant_roles.name) = ?', ['admin'])
            ->pluck('tenant_users.id');
        DB::table('tenant_user_permissions')->whereIn('tenant_user_id', $adminIds)->update(array_fill_keys(self::PERMISSIONS, true));

        DB::table('features')->updateOrInsert(['code' => 'automatic_open_close'], [
            'name' => 'Automatic accounting day open and close',
            'description' => 'Configure weekly accounting day opening and closing schedules.',
            'is_active' => true,
            'is_deleted' => false,
            'update_key' => 0,
            'updated_at' => now(),
        ]);
        $featureId = DB::table('features')->where('code', 'automatic_open_close')->value('id');
        $premiumIds = DB::table('packages')->where('code', 'premium')->pluck('id');

        foreach ($premiumIds as $premiumId) {
            DB::table('package_features')->updateOrInsert(
                ['package_id' => $premiumId, 'feature_id' => $featureId],
                ['is_enabled' => true, 'is_deleted' => false, 'update_key' => 0, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        $featureId = DB::table('features')->where('code', 'automatic_open_close')->value('id');

        if ($featureId !== null) {
            DB::table('package_features')->where('feature_id', $featureId)->delete();
        }

        DB::table('features')->where('code', 'automatic_open_close')->delete();

        foreach (['tenant_roles', 'tenant_user_permissions'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn(self::PERMISSIONS));
        }
    }
};
