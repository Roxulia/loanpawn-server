<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Addition of customer to person linkage
        Schema::table('tenant_customers', function (Blueprint $table): void {
            $table->foreignId('person_id')->nullable()->after('tenant_id')->constrained('tenant_people', indexName: 'tc_person_fk')->nullOnDelete();
            $table->index(['tenant_id', 'person_id'], 'tc_tenant_person_idx');
        });

        // Backfill of person records from existing customers
        DB::table('tenant_customers')->orderBy('id')->chunkById(200, function ($customers): void {
            foreach ($customers as $customer) {
                $personId = DB::table('tenant_people')->insertGetId([
                    'tenant_id' => $customer->tenant_id,
                    'name' => $customer->name,
                    'nrc' => $customer->nrc,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                    'address' => $customer->address,
                    'note' => $customer->note,
                    'created_by' => $customer->created_by,
                    'created_at' => $customer->created_at,
                    'updated_at' => $customer->updated_at,
                    'deleted_at' => $customer->deleted_at,
                    'is_deleted' => $customer->is_deleted,
                ]);

                // Update of customer linkage to the created person
                DB::table('tenant_customers')->where('id', $customer->id)->update(['person_id' => $personId]);
            }
        });
    }

    public function down(): void
    {
        // Collection of backfilled person records before removing the linkage column
        $personIds = DB::table('tenant_customers')
            ->whereNotNull('person_id')
            ->pluck('person_id');

        // Removal of customer to person linkage
        Schema::table('tenant_customers', function (Blueprint $table): void {
            $table->dropForeign('tc_person_fk');
            $table->dropIndex('tc_tenant_person_idx');
            $table->dropColumn('person_id');
        });

        // Cleanup of people created for the customer backfill
        if ($personIds->isNotEmpty()) {
            DB::table('tenant_people')->whereIn('id', $personIds)->delete();
        }
    }
};
