<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_categories', function (Blueprint $table) {
            $table->id();
            $table->integer('update_key')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->string('code', 60)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('id')->constrained('tenant_categories')->restrictOnDelete();
            $table->unsignedInteger('rank')->default(0)->after('category_id');
            $table->boolean('is_trial')->default(false)->after('rank')->index();
        });

        Schema::table('tenant_licenses', function (Blueprint $table) {
            $table->string('plan_type', 60)->change();
        });
        Schema::table('tenant_requests', function (Blueprint $table) {
            $table->string('requested_plan_type', 60)->nullable()->change();
        });
        Schema::table('tenant_license_plan_transitions', function (Blueprint $table) {
            $table->string('from_plan_type', 60)->change();
            $table->string('to_plan_type', 60)->change();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('platform_user_id')->constrained('tenant_categories')->restrictOnDelete();
        });

        Schema::table('tenant_licenses', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('tenant_id')->constrained('packages')->restrictOnDelete();
        });

        Schema::table('tenant_requests', function (Blueprint $table) {
            $table->foreignId('requested_category_id')->nullable()->after('tenant_id')->constrained('tenant_categories')->restrictOnDelete();
            $table->foreignId('requested_plan_id')->nullable()->after('requested_category_id')->constrained('packages')->restrictOnDelete();
        });

        Schema::table('tenant_license_plan_transitions', function (Blueprint $table) {
            $table->foreignId('from_plan_id')->nullable()->after('tenant_request_id')->constrained('packages')->restrictOnDelete();
            $table->foreignId('to_plan_id')->nullable()->after('from_plan_id')->constrained('packages')->restrictOnDelete();
        });

        $now = now();
        $pawnCategoryId = DB::table('tenant_categories')->insertGetId([
            'code' => 'pawn-shop',
            'name' => 'Pawn Shop',
            'description' => 'Pawn shop operations, customers, collateral, loans, and accounting.',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $budgetingCategoryId = DB::table('tenant_categories')->insertGetId([
            'code' => 'budgeting',
            'name' => 'Budgeting',
            'description' => 'Accounting, expenses, capital, and debt management.',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach (config('package_features.packages', []) as $code => $definition) {
            DB::table('packages')->updateOrInsert(
                ['code' => $code],
                [
                    'category_id' => $pawnCategoryId,
                    'rank' => match ($code) { 'trial' => 0, 'basic' => 100, default => 200 },
                    'is_trial' => $code === 'trial',
                    'name' => $definition['name'],
                    'description' => $definition['description'] ?? null,
                    'price' => $definition['price'],
                    'max_slip_per_month' => $definition['max_slip_per_month'] ?? null,
                    'max_staff_count' => $definition['max_staff_count'] ?? null,
                    'is_active' => $definition['is_active'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        DB::table('packages')->where('code', 'trial')->update([
            'category_id' => $pawnCategoryId,
            'rank' => 0,
            'is_trial' => true,
        ]);
        DB::table('packages')->where('code', 'basic')->update([
            'category_id' => $pawnCategoryId,
            'rank' => 100,
        ]);
        DB::table('packages')->where('code', 'premium')->update([
            'category_id' => $pawnCategoryId,
            'rank' => 200,
        ]);

        DB::table('packages')->whereNull('category_id')->update(['category_id' => $pawnCategoryId]);

        foreach ([
            ['code' => 'budgeting-trial', 'name' => 'Budgeting Trial', 'rank' => 0, 'is_trial' => true, 'is_active' => true],
            ['code' => 'budgeting-basic', 'name' => 'Budgeting Basic', 'rank' => 100, 'is_trial' => false, 'is_active' => false],
            ['code' => 'budgeting-premium', 'name' => 'Budgeting Premium', 'rank' => 200, 'is_trial' => false, 'is_active' => false],
        ] as $budgetPlan) {
            $packageId = DB::table('packages')->insertGetId([
                'category_id' => $budgetingCategoryId,
                'rank' => $budgetPlan['rank'],
                'is_trial' => $budgetPlan['is_trial'],
                'code' => $budgetPlan['code'],
                'name' => $budgetPlan['name'],
                'description' => 'Budgeting plan with accounting-only features.',
                'price' => 0,
                'max_slip_per_month' => null,
                'max_staff_count' => null,
                'is_active' => $budgetPlan['is_active'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $accountingFeatureIds = DB::table('features')
                ->whereIn('code', ['accounting_management', 'expense_management', 'capital_management', 'debt_management'])
                ->pluck('id')
                ->all();

            foreach (DB::table('features')->pluck('id') as $featureId) {
                DB::table('package_features')->insert([
                    'package_id' => $packageId,
                    'feature_id' => $featureId,
                    'is_enabled' => in_array($featureId, $accountingFeatureIds, true),
                    'value' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
        DB::table('tenants')->whereNull('category_id')->update(['category_id' => $pawnCategoryId]);

        DB::table('tenant_licenses')->orderBy('id')->each(function ($license): void {
            $planId = DB::table('packages')->where('code', $license->plan_type)->value('id');
            if ($planId) {
                DB::table('tenant_licenses')->where('id', $license->id)->update(['plan_id' => $planId]);
            }
        });

        DB::table('tenant_requests')->orderBy('id')->each(function ($request) use ($pawnCategoryId): void {
            $planId = DB::table('packages')->where('code', $request->requested_plan_type)->value('id');
            DB::table('tenant_requests')->where('id', $request->id)->update([
                'requested_category_id' => $pawnCategoryId,
                'requested_plan_id' => $planId,
            ]);
        });

        DB::table('tenant_license_plan_transitions')->orderBy('id')->each(function ($transition): void {
            DB::table('tenant_license_plan_transitions')->where('id', $transition->id)->update([
                'from_plan_id' => DB::table('packages')->where('code', $transition->from_plan_type)->value('id'),
                'to_plan_id' => DB::table('packages')->where('code', $transition->to_plan_type)->value('id'),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_license_plan_transitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('to_plan_id');
            $table->dropConstrainedForeignId('from_plan_id');
        });
        Schema::table('tenant_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_plan_id');
            $table->dropConstrainedForeignId('requested_category_id');
        });
        Schema::table('tenant_licenses', fn (Blueprint $table) => $table->dropConstrainedForeignId('plan_id'));
        Schema::table('tenants', fn (Blueprint $table) => $table->dropConstrainedForeignId('category_id'));
        Schema::table('packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn(['rank', 'is_trial']);
        });
        Schema::dropIfExists('tenant_categories');
    }
};
