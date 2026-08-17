<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reporting_currency_recalculations', function (Blueprint $table): void {
            $table->foreignId('initiated_by_tenant_user_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('tenant_users')
                ->nullOnDelete()
                ->name('rcr_initiated_by_tenant_user_id_foreign');
        });

        Schema::create('tenant_user_notifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tenant_user_id')->constrained('tenant_users')->cascadeOnDelete();
            $table->foreignId('reporting_currency_recalculation_id')
                ->nullable()
                ->constrained('reporting_currency_recalculations')
                ->nullOnDelete()
                ->name('tun_reporting_currency_recalculation_id_foreign');
            $table->string('type', 80);
            $table->string('status', 30);
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'tenant_user_id', 'created_at'], 'tun_tenant_user_created_idx');
            $table->index(['tenant_id', 'tenant_user_id', 'read_at'], 'tun_tenant_user_read_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_user_notifications');

        Schema::table('reporting_currency_recalculations', function (Blueprint $table): void {
            $table->dropForeign('rcr_initiated_by_tenant_user_id_foreign');
            $table->dropColumn('initiated_by_tenant_user_id');
        });
    }
};
