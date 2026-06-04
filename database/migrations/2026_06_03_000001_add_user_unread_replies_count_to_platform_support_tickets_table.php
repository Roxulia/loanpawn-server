<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_support_tickets', function (Blueprint $table) {
            $table->unsignedInteger('user_unread_replies_count')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('platform_support_tickets', function (Blueprint $table) {
            $table->dropColumn('user_unread_replies_count');
        });
    }
};
