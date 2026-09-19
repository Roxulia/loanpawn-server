<?php

use App\Models\CoreModule\TenantSetting;
use App\Models\PlatformModule\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_customers', function (Blueprint $table): void {
            $table->boolean('is_auto_generated')->default(false)->after('is_deleted')->index();
        });

        $now = now();
        $settings = Tenant::query()
            ->select('id')
            ->get()
            ->map(fn (Tenant $tenant): array => [
                'tenant_id' => $tenant->id,
                'key' => 'loan_slip_creation_settings',
                'value' => json_encode(['customer_info_required' => true]),
                'category' => 'tenant',
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($settings !== []) {
            TenantSetting::query()->withoutGlobalScopes()->insertOrIgnore($settings);
        }
    }

    public function down(): void
    {
        TenantSetting::query()
            ->withoutGlobalScopes()
            ->where('key', 'loan_slip_creation_settings')
            ->delete();

        Schema::table('tenant_customers', function (Blueprint $table): void {
            $table->dropIndex(['is_auto_generated']);
            $table->dropColumn('is_auto_generated');
        });
    }
};
