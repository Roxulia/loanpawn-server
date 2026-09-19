<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SETTING_KEY = 'allow_partial_business_loan_payments';

    public function up(): void
    {
        // Creation of the independent partial payment policy
        $now = now();
        $settings = DB::table('tenants')->pluck('id')->map(fn ($tenantId): array => [
            'tenant_id' => $tenantId,
            'key' => self::SETTING_KEY,
            'value' => 'false',
            'category' => 'tenant',
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        // Insert settings only when tenants exist
        if ($settings !== []) {
            DB::table('tenant_settings')->insertOrIgnore($settings);
        }
    }

    public function down(): void
    {
        // Removal of the independent partial payment policy
        DB::table('tenant_settings')->where('key', self::SETTING_KEY)->delete();
    }
};
