<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'list_business_loan',
        'create_business_loan',
        'update_business_loan',
        'delete_business_loan',
        'list_lender',
        'create_lender',
        'update_lender',
        'delete_lender',
    ];

    public function up(): void
    {
        // Creation of the shared tenant person records
        Schema::create('tenant_people', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->string('name', 120);
            $table->string('nrc')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'nrc']);
            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'phone']);
        });

        // Linkage of existing customers to their shared person
        Schema::table('tenant_customers', function (Blueprint $table): void {
            $table->foreignId('person_id')->nullable()->after('tenant_id')->constrained('tenant_people')->nullOnDelete();
            $table->index(['tenant_id', 'person_id']);
        });

        // Creation of lender profiles backed by shared people
        Schema::create('tenant_lenders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('tenant_people')->restrictOnDelete();
            $table->string('code');
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->foreignId('created_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'person_id']);
        });

        // Backfill of every customer and its matching lender profile
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

                DB::table('tenant_customers')->where('id', $customer->id)->update(['person_id' => $personId]);
                DB::table('tenant_lenders')->insert([
                    'tenant_id' => $customer->tenant_id,
                    'person_id' => $personId,
                    'code' => 'LDR-'.str_pad((string) $customer->id, 8, '0', STR_PAD_LEFT),
                    'is_deleted' => $customer->is_deleted,
                    'created_by' => $customer->created_by,
                    'created_at' => $customer->created_at,
                    'updated_at' => $customer->updated_at,
                    'deleted_at' => $customer->deleted_at,
                ]);
            }
        });

        // Creation of business loan principal records
        Schema::create('tenant_business_loans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('lender_id')->nullable()->constrained('tenant_lenders')->nullOnDelete();
            $table->foreignId('receipt_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->string('code');
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->decimal('amount', 14, 2);
            $table->decimal('principal_balance', 14, 2);
            $table->boolean('apply_interest')->default(false);
            $table->decimal('interest_rate', 8, 4)->nullable();
            $table->foreignId('interest_type_id')->nullable()->constrained('interest_types')->nullOnDelete();
            $table->timestamp('interest_anchor_at')->nullable();
            $table->timestamp('last_interest_paid_at')->nullable();
            $table->boolean('compound_schedule_enabled')->default(false)->index();
            $table->unsignedInteger('compound_every')->nullable();
            $table->string('compound_every_type', 20)->nullable();
            $table->timestamp('next_compound_at')->nullable()->index();
            $table->timestamp('last_compounded_at')->nullable();
            $table->text('description');
            $table->string('tag', 120)->nullable();
            $table->boolean('is_paid')->default(false)->index();
            $table->foreignId('created_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'lender_id', 'is_paid']);
        });

        // Creation of business loan interest periods
        Schema::create('tenant_business_loan_interest_accruals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('business_loan_id')->constrained('tenant_business_loans')->cascadeOnDelete();
            $table->decimal('principal_amount', 14, 2);
            $table->decimal('calculated_interest', 14, 2);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('compounded_amount', 14, 2)->default(0);
            $table->timestamp('compounded_at')->nullable();
            $table->timestamp('start_period_at')->index();
            $table->timestamp('end_period_at');
            $table->string('period_timezone', 64)->nullable();
            $table->boolean('is_paid')->default(false)->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'business_loan_id', 'start_period_at'], 'business_loan_interest_period_unique');
        });

        // Creation of business loan payments and their interest allocations
        Schema::create('tenant_business_loan_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('business_loan_id')->constrained('tenant_business_loans')->cascadeOnDelete();
            $table->foreignId('payment_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->string('code');
            $table->enum('allocation_order', ['interest_first', 'principal_first'])->default('interest_first');
            $table->decimal('payment_amount', 14, 2);
            $table->decimal('principal_paid', 14, 2)->default(0);
            $table->decimal('interest_paid', 14, 2)->default(0);
            $table->timestamp('payment_at');
            $table->foreignId('created_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('tenant_business_loan_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('tenant_business_loan_payments')->cascadeOnDelete();
            $table->foreignId('accrual_id')->constrained('tenant_business_loan_interest_accruals')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamps();
            $table->unique(['payment_id', 'accrual_id']);
        });

        // Addition of assignable permissions for both resources
        foreach (['tenant_roles', 'tenant_user_permissions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                foreach (self::PERMISSIONS as $permission) {
                    $table->boolean($permission)->default(false);
                }
            });
        }

        DB::table('tenant_roles')->whereIn(DB::raw('LOWER(name)'), ['owner', 'admin'])->update(array_fill_keys(self::PERMISSIONS, true));
        $managerUserIds = DB::table('tenant_users')->join('tenant_roles', 'tenant_roles.id', '=', 'tenant_users.role_id')->whereIn(DB::raw('LOWER(tenant_roles.name)'), ['owner', 'admin'])->pluck('tenant_users.id');
        DB::table('tenant_user_permissions')->whereIn('tenant_user_id', $managerUserIds)->update(array_fill_keys(self::PERMISSIONS, true));

        // Registration of feature gates and package mappings
        foreach ([
            'business_loan_management' => ['Business Loan Management', 'Manage tenant business loans and lender repayments.'],
            'lender_management' => ['Lender Management', 'Manage tenant lender identity records.'],
        ] as $code => [$name, $description]) {
            DB::table('features')->updateOrInsert(['code' => $code], [
                'name' => $name,
                'description' => $description,
                'is_active' => true,
                'is_deleted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $featureId = DB::table('features')->where('code', $code)->value('id');
            $debtFeatureId = DB::table('features')->where('code', 'debt_management')->value('id');
            foreach (DB::table('package_features')->where('feature_id', $debtFeatureId)->get() as $mapping) {
                DB::table('package_features')->updateOrInsert(
                    ['package_id' => $mapping->package_id, 'feature_id' => $featureId],
                    ['is_enabled' => $mapping->is_enabled, 'value' => null, 'is_deleted' => false, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }

        // Creation of the independent partial payment policy
        $now = now();
        $settings = DB::table('tenants')->pluck('id')->map(fn ($tenantId): array => [
            'tenant_id' => $tenantId,
            'key' => 'allow_partial_business_loan_payments',
            'value' => 'false',
            'category' => 'tenant',
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();
        if ($settings !== []) {
            DB::table('tenant_settings')->insertOrIgnore($settings);
        }
    }

    public function down(): void
    {
        // Removal in reverse dependency order
        DB::table('tenant_settings')->where('key', 'allow_partial_business_loan_payments')->delete();
        $featureIds = DB::table('features')->whereIn('code', ['business_loan_management', 'lender_management'])->pluck('id');
        DB::table('package_features')->whereIn('feature_id', $featureIds)->delete();
        DB::table('features')->whereIn('id', $featureIds)->delete();
        foreach (['tenant_user_permissions', 'tenant_roles'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn(self::PERMISSIONS));
        }
        Schema::dropIfExists('tenant_business_loan_payment_allocations');
        Schema::dropIfExists('tenant_business_loan_payments');
        Schema::dropIfExists('tenant_business_loan_interest_accruals');
        Schema::dropIfExists('tenant_business_loans');
        Schema::dropIfExists('tenant_lenders');
        Schema::table('tenant_customers', fn (Blueprint $table) => $table->dropConstrainedForeignId('person_id'));
        Schema::dropIfExists('tenant_people');
    }
};
