<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add the same explicit compounding state already used by debt and business-loan accruals.
        Schema::table('pawn_interest_payments', function (Blueprint $table): void {
            $table->decimal('compounded_amount', 14, 2)->default(0)->after('payment_amount');
            $table->timestamp('compounded_at')->nullable()->after('compounded_amount');
        });
    }

    public function down(): void
    {
        // Remove only the newly introduced pawn compounding fields during rollback.
        Schema::table('pawn_interest_payments', function (Blueprint $table): void {
            $table->dropColumn(['compounded_amount', 'compounded_at']);
        });
    }
};
