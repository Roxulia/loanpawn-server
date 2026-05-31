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
        Schema::create('platform_table_codes', function (Blueprint $table) {
            $table->id();
            $table->string('table_name')->nullable(false);
            $table->integer('current_year')->nullable(false);
            $table->integer('current_month')->nullable(false);
            $table->integer('current_id')->default(0);
            $table->timestamps();

            $table->unique(['table_name','current_year','current_month'],"platform_table_codes_unique_constraint");
            $table->index(['table_name','current_year','current_month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_table_codes');
    }
};
