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
        Schema::create('manual_payment_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->index();
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->foreignId('manual_payment_request_id')->constrained('manual_payment_requests')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_type', 80)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('manual_payment_attachments');
    }
};
