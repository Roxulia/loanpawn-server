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
        Schema::create('tenant_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_code')->index();
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('address',100)->nullable();
            $table->string('phone',20)->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->timestamps();
            $table->foreign('tenant_code')->references('tenant_code')->on('tenants')->cascadeOnDelete();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_contacts');
    }
};
