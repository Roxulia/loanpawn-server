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
        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('account_type_id')->index();
            $table->integer('update_key')->default(0);
            $table->unsignedBigInteger('currency_id')->index();
            $table->string('account_number', 50);
            $table->string('account_name', 100);
            $table->string('account_code', 30);
            $table->decimal('balance', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_deleted')->default(false);
            $table->boolean('allow_negative_balance')->default(false);
            $table->unsignedBigInteger('deleted_by')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('account_type_id')->references('id')->on('financial_account_types')->onDelete('cascade');
            $table->foreign('currency_id')->references('id')->on('currencies')->onDelete('cascade');
            $table->foreign('deleted_by')->references('id')->on('tenant_users')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('tenant_users')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_accounts');
    }
};
