<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_user_email_verification_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('consumed_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_user_email_verification_tokens');
    }
};
