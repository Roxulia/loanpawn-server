<?php

use App\Models\CoreModule\TenantCustomer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_customers', function (Blueprint $table): void {
            $table->unsignedTinyInteger('trust_score')
                ->default(TenantCustomer::DEFAULT_TRUST_SCORE)
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_customers', function (Blueprint $table): void {
            $table->unsignedTinyInteger('trust_score')->default(0)->change();
        });
    }
};
