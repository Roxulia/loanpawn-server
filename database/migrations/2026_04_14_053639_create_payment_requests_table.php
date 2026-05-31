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
        Schema::create('manual_payment_requests', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->index();
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->foreignId('platform_user_id')->constrained('platform_users')->cascadeOnDelete();
            $table->foreignId('tenant_request_id')->nullable()->constrained('tenant_requests')->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 10)->default('MMK');
            $table->string('payment_reference', 120)->nullable()->index();
            $table->text('note')->nullable();
            $table->enum('status', ['draft', 'submitted', 'under_review', 'approved', 'rejected'])
                ->default('draft')
                ->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('manual_payment_requests');
    }
};
