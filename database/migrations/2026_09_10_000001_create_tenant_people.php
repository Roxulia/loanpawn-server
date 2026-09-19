<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Creation of shared tenant person records
        Schema::create('tenant_people', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants', indexName: 'tp_tenant_fk')->cascadeOnDelete();
            $table->integer('update_key')->default(0)->index('tp_update_idx');
            $table->boolean('is_deleted')->default(false)->index('tp_deleted_idx');
            $table->string('name', 120);
            $table->string('nrc')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('tenant_users', indexName: 'tp_created_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'nrc'], 'tp_tenant_nrc_idx');
            $table->index(['tenant_id', 'email'], 'tp_tenant_email_idx');
            $table->index(['tenant_id', 'phone'], 'tp_tenant_phone_idx');
        });
    }

    public function down(): void
    {
        // Removal of shared tenant person records
        Schema::dropIfExists('tenant_people');
    }
};
