<?php

use App\Models\CoreModule\TenantUser;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
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
        Schema::table('platform_users', function (Blueprint $table) {
            $table->string('code',20)->change();
            $table->string('prefer_lang', 2)->default('en')->after('remember_token');
        });
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->string('prefer_lang', 2)->default('en')->after('remember_token');
        });
        PlatformUser::query()->update(['prefer_lang' => 'en']);
        TenantUser::query()->update(['prefer_lang' => 'en']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_users', function (Blueprint $table) {
            $table->string('code', 10)->change();
            $table->dropColumn('prefer_lang');
        });
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropColumn('prefer_lang');
        });
    }
};
