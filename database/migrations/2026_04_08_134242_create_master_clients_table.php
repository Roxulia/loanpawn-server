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
        Schema::create('platform_users', function (Blueprint $table) {
            $table->id();
            $table->string('code',10)->unique()->index();
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone', 30)->nullable()->index();
            $table->string('password');
            $table->string('status', 20)->default('active')->index();
            $table->rememberToken();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('platform_user_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('update_key')->nullable()->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_user_sessions');
        Schema::dropIfExists('platform_user_password_reset_tokens');
        Schema::dropIfExists('platform_users');
    }
};
