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
        Schema::create('tenant_roles', function (Blueprint $table) {
            $table->id();
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->string('name', 50);
            $table->string('description')->nullable();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('dashboard')->default(false);
            $table->boolean('access_all')->default(false);
            $table->boolean('list_user')->default(false);
            $table->boolean('create_user')->default(false);
            $table->boolean('delete_user')->default(false);
            $table->boolean('update_user_admin')->default(false);
            $table->boolean('update_user_all')->default(false);
            $table->boolean('update_user_own')->default(false);
            $table->boolean('list_customer')->default(false);
            $table->boolean('create_customer')->default(false);
            $table->boolean('delete_customer')->default(false);
            $table->boolean('update_customer')->default(false);
            $table->boolean('list_collateral')->default(false);
            $table->boolean('create_collateral')->default(false);
            $table->boolean('update_collateral')->default(false);
            $table->boolean('delete_collateral')->default(false);
            $table->boolean('list_accounting')->default(false);
            $table->boolean('create_accounting')->default(false);
            $table->boolean('update_accounting')->default(false);
            $table->boolean('delete_accounting')->default(false);
            $table->boolean('list_expense')->default(false);
            $table->boolean('create_expense')->default(false);
            $table->boolean('update_expense')->default(false);
            $table->boolean('delete_expense')->default(false);
            $table->boolean('list_capital')->default(false);
            $table->boolean('create_capital')->default(false);
            $table->boolean('update_capital')->default(false);
            $table->boolean('delete_capital')->default(false);
            $table->boolean('list_debt')->default(false);
            $table->boolean('create_debt')->default(false);
            $table->boolean('update_debt')->default(false);
            $table->boolean('delete_debt')->default(false);
            $table->boolean('list_loan_contract')->default(false);
            $table->boolean('create_loan_contract')->default(false);
            $table->boolean('delete_loan_contract')->default(false);
            $table->boolean('manage_slip_document')->default(false);
            $table->timestamps();

            $table->unique('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_roles');
    }
};
