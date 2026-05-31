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
        Schema::create('pawn_redemptions', function (Blueprint $table) {
            $table->id();
            $table->string('slip_number');
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('slip_id')->constrained('pawn_loan_contract_slips')->cascadeOnDelete();
            $table->decimal('gross_amount', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2)->default(0);
            $table->decimal('interest_amount', 14, 2)->default(0);
            $table->decimal('received_amount', 14, 2)->default(0);
            $table->decimal('change_amount', 14, 2)->default(0);
            $table->date('redemption_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'slip_id']);
            $table->unique(['tenant_id','slip_number']);
            $table->index(['tenant_id','slip_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pawn_redemptions');
    }
};
