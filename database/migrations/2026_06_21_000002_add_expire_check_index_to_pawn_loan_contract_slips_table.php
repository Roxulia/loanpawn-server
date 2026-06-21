<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pawn_loan_contract_slips', function (Blueprint $table): void {
            $table->index(['is_deleted', 'status', 'expire_date'], 'pawn_slips_expire_check_index');
        });
    }

    public function down(): void
    {
        Schema::table('pawn_loan_contract_slips', function (Blueprint $table): void {
            $table->dropIndex('pawn_slips_expire_check_index');
        });
    }
};
