<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Creation of lender profiles backed by shared people
        Schema::create('tenant_lenders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants', indexName: 'tl_tenant_fk')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('tenant_people', indexName: 'tl_person_fk')->restrictOnDelete();
            $table->string('code');
            $table->integer('update_key')->default(0)->index('tl_update_idx');
            $table->boolean('is_deleted')->default(false)->index('tl_deleted_idx');
            $table->foreignId('created_by')->nullable()->constrained('tenant_users', indexName: 'tl_created_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'code'], 'tl_tenant_code_unique');
            $table->unique(['tenant_id', 'person_id'], 'tl_tenant_person_unique');
        });

        // Backfill of lender profiles from existing customer people
        DB::table('tenant_customers')->whereNotNull('person_id')->orderBy('id')->chunkById(200, function ($customers): void {
            foreach ($customers as $customer) {
                DB::table('tenant_lenders')->insert([
                    'tenant_id' => $customer->tenant_id,
                    'person_id' => $customer->person_id,
                    'code' => 'LDR-'.str_pad((string) $customer->id, 8, '0', STR_PAD_LEFT),
                    'is_deleted' => $customer->is_deleted,
                    'created_by' => $customer->created_by,
                    'created_at' => $customer->created_at,
                    'updated_at' => $customer->updated_at,
                    'deleted_at' => $customer->deleted_at,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Removal of lender profiles
        Schema::dropIfExists('tenant_lenders');
    }
};
