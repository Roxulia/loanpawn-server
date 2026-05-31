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
        Schema::create('tenant_users', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('tenant_roles')->nullOnDelete();
            $table->string('username', 8);
            $table->string('name', 120);
            $table->string('nrc');
            $table->string('email');
            $table->string('phone', 30)->nullable();
            $table->string('address',100)->nullable();
            $table->string('password');
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('last_login_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->rememberToken();
            $table->timestamps();

            $table->unique(['tenant_id', 'username']);
            $table->unique(['tenant_id', 'email']);
            $table->unique(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'is_deleted']);
            $table->unique(['tenant_id','nrc']);
            $table->unique(['tenant_id','code']);

            $table->index(['tenant_id','code']);
        });

        Schema::create('tenant_user_permissions', function (Blueprint $table) {
            $table->id();
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tenant_user_id')->constrained('tenant_users')->cascadeOnDelete();
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
            $table->boolean('list_debt')->default(false);
            $table->boolean('create_debt')->default(false);
            $table->boolean('update_debt')->default(false);
            $table->boolean('delete_debt')->default(false);
            $table->boolean('list_loan_contract')->default(false);
            $table->boolean('create_loan_contract')->default(false);
            $table->boolean('delete_loan_contract')->default(false);
            $table->boolean('manage_slip_document')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'tenant_user_id']);
            $table->index('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_user_permissions');
        Schema::dropIfExists('tenant_users');
    }
};
