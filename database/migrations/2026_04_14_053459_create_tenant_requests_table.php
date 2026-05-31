<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tenant_requests', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->index();
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('platform_user_id')->constrained('platform_users')->cascadeOnDelete();
            $table->enum('request_type', ['extension','plan_change'])->nullable();
            $table->enum('requested_plan_type', ['basic', 'premium'])->index();
            $table->unsignedSmallInteger('extension_months')->nullable();
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->string('currency', 10)->default('MMK');
            $table->string('requested_subdomain', 63)->nullable();
            $table->json('business_info')->nullable();
            $table->enum('request_status', ['waiting_payment','pending_approval','approved','declined'])->default('waiting_payment')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_review_note')->nullable();
            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_requests');
    }
};
