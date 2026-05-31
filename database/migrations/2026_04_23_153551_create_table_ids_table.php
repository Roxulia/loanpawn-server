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
        Schema::create('table_ids', function (Blueprint $table) {
            $table->id();
            $table->string('update_key')->nullable()->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('table_name')->nullable(false);
            $table->integer('current_year')->nullable(false);
            $table->integer('current_month')->nullable(false);
            $table->integer('current_id')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id','table_name','current_year','current_month']);
            $table->index(['tenant_id','table_name','current_year','current_month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('table_ids');
    }
};
