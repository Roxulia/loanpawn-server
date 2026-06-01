<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('features', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->index()->after('description');
        });

        Schema::create('tenant_license_plan_transitions', function (Blueprint $table) {
            $table->id();
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->foreignId('tenant_license_id')->constrained('tenant_licenses')->cascadeOnDelete();
            $table->foreignId('tenant_request_id')->unique()->constrained('tenant_requests')->cascadeOnDelete();
            $table->enum('from_plan_type', ['trial', 'basic', 'premium']);
            $table->enum('to_plan_type', ['trial', 'basic', 'premium']);
            $table->timestamp('starts_at')->index();
            $table->timestamp('expires_at');
            $table->enum('status', ['scheduled', 'activated', 'cancelled'])->default('scheduled')->index();
            $table->foreignId('approved_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_license_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_license_plan_transitions');

        Schema::table('features', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
