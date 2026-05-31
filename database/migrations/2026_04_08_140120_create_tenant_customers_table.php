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
        Schema::create('tenant_customers', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->unsignedTinyInteger('trust_score')->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'is_deleted']);
            $table->unique(['tenant_id','email']);
            $table->unique(['tenant_id','phone']);
            $table->unique(['tenant_id','code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_customers');
    }
};
