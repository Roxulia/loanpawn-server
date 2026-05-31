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
        Schema::create('tenant_accountings', function (Blueprint $table) {
            $table->id();
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('description');
            $table->string('transaction_type', 30)->index();
            $table->decimal('amount', 14, 2);
            $table->foreignId('created_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_type', 80)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_accountings');
    }
};
