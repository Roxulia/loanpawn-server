<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->index();
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->foreignId('platform_user_id')->constrained(
                table:'platform_users',
                indexName: 'support_ticket_platform_user_id_foreign'
                )->cascadeOnDelete();
            $table->string('subject', 180);
            $table->enum('type', ['bugs', 'features', 'support'])->index();
            $table->enum('status', ['pending', 'open', 'resolved'])->default('pending')->index();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('platform_support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_support_ticket_id')
                ->constrained(table:'platform_support_tickets',indexName:'support_ticket_message_ticket_id_foreign')
                ->cascadeOnDelete();
            $table->enum('sender_type', ['platform_user', 'platform_admin'])->index();
            $table->foreignId('platform_user_id')->nullable()->constrained(table:'platform_users',indexName:'support_ticket_message_user_id_foreign')->nullOnDelete();
            $table->foreignId('platform_admin_id')->nullable()->constrained(table:'platform_admins',indexName:'support_ticket_message_admin_id_foreign')->nullOnDelete();
            $table->text('message');
            $table->timestamps();
        });

        Schema::create('platform_support_ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->index();
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->foreignId('platform_support_ticket_message_id')
                ->constrained(table:'platform_support_ticket_messages',indexName:'support_ticket_attachment_message_id_foreign')
                ->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_type', 120)->nullable();
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->enum('uploaded_by_type', ['platform_user', 'platform_admin'])->index();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained(table:'platform_users',indexName:'support_ticket_attachment_user_id_foreign')->nullOnDelete();
            $table->foreignId('uploaded_by_admin_id')->nullable()->constrained(table:'platform_admins',indexName:'support_ticket_attachment_admin_id_foreign')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_support_ticket_attachments');
        Schema::dropIfExists('platform_support_ticket_messages');
        Schema::dropIfExists('platform_support_tickets');
    }
};
