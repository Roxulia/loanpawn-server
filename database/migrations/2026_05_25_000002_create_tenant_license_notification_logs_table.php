<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_license_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained('tenant_licenses')->cascadeOnDelete();
            $table->string('notification_type', 80);
            $table->unsignedSmallInteger('threshold_days');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['license_id', 'notification_type', 'threshold_days'], 'license_notification_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_license_notification_logs');
    }
};
