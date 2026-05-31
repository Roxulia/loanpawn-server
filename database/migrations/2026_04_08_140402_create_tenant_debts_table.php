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
        Schema::create('tenant_debts', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('slip_id')->nullable()->constrained('pawn_loan_contract_slips')->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('description');
            $table->string('tag', 80)->nullable()->index();
            $table->boolean('is_paid')->default(false)->index();
            $table->foreignId('accepted_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'slip_id']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_debts');
    }
};
