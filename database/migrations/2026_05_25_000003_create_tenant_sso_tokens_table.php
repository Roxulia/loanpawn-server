<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_sso_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('platform_user_id')->constrained('platform_users')->cascadeOnDelete();
            $table->foreignId('tenant_user_id')->constrained('tenant_users')->cascadeOnDelete();
            $table->string('token_hash');
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['tenant_id', 'expires_at', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_sso_tokens');
    }
};
