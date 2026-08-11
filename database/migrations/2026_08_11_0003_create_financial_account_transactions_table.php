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
        Schema::create('financial_account_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('financial_account_id')->index();
            $table->string('transaction_type', 30);
            $table->decimal('amount', 15, 2);
            $table->enum('direction', ['credit', 'debit']);
            $table->string('reference_number', 100)->nullable();
            $table->string('reference_type', 100)->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('related_transaction_id')->nullable()->index();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('financial_account_id')->references('id')->on('financial_accounts')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('tenant_users')->onDelete('set null');
            $table->foreign('related_transaction_id')->references('id')->on('tenant_accountings')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_account_transactions');
    }
};
