<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_licenses', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->change();
        });

        DB::table('tenant_licenses')
            ->where(function ($query): void {
                $query->where('plan_type', 'trial')
                    ->orWhere('plan_type', 'like', '%-trial');
            })
            ->update([
                'expires_at' => null,
                'status' => DB::raw("CASE WHEN status = 'expired' THEN 'active' ELSE status END"),
            ]);
    }

    public function down(): void
    {
        // Free licenses intentionally have no expiry. Existing expiry values cannot be restored safely.
    }
};
