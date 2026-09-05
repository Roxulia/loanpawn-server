<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_debts', function (Blueprint $table) {
            $table->boolean('apply_interest')->default(false)->after('amount')->index();
            $table->decimal('principal_balance', 14, 2)->default(0)->after('apply_interest');
            $table->decimal('interest_rate', 8, 4)->nullable()->after('principal_balance');
            $table->foreignId('interest_type_id')->nullable()->after('interest_rate')->constrained('interest_types')->nullOnDelete();
            $table->timestamp('interest_anchor_at')->nullable()->after('interest_type_id');
            $table->timestamp('last_interest_paid_at')->nullable()->after('interest_anchor_at');
        });

        DB::table('tenant_debts')->where('is_paid', false)->update(['principal_balance' => DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('tenant_debts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('interest_type_id');
            $table->dropColumn([
                'apply_interest',
                'principal_balance',
                'interest_rate',
                'interest_anchor_at',
                'last_interest_paid_at',
            ]);
        });
    }
};
