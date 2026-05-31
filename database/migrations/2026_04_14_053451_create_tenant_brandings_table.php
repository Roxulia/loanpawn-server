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
        Schema::create('tenant_branding', function (Blueprint $table) {
            $table->id();
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('tenant_code');
            $table->string('logo_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->string('primary_color', 20)->nullable();
            $table->string('secondary_color', 20)->nullable();
            $table->string('accent_color', 20)->nullable();
            $table->longText('slip_header_layout')->nullable();
            $table->longText('slip_footer_layout')->nullable();
            $table->timestamps();
            $table->foreign('tenant_code')->references('tenant_code')->on('tenants')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_branding');
    }
};
