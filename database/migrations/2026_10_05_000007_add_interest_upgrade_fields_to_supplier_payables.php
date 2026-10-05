<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Reserve disabled interest settings for future payable-interest support.
    public function up(): void
    {
        Schema::table('supplier_payables', function (Blueprint $table): void {
            $table->boolean('apply_interest')->default(false)->after('balance_amount')->index('sp_apply_interest_ix');
            $table->decimal('interest_rate', 8, 4)->nullable()->after('apply_interest');
            $table->foreignId('interest_type_id')->nullable()->after('interest_rate')->constrained('interest_types', indexName: 'sp_interest_type_fk')->nullOnDelete();
        });
    }

    // Remove only the unused future-interest settings when rolling back.
    public function down(): void
    {
        Schema::table('supplier_payables', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('interest_type_id');
            $table->dropIndex('sp_apply_interest_ix');
            $table->dropColumn(['apply_interest', 'interest_rate']);
        });
    }
};
