<?php

use App\Models\PlatformModule\Feature;
use App\Models\PlatformModule\Package;
use App\Models\PlatformModule\PackageFeature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'manage_interest_process_settings',
        'manage_slip_compound_schedule',
        'compound_slip_interest',
        'collect_partial_principal',
    ];

    public function up(): void
    {
        Schema::table('tenant_roles', function (Blueprint $table): void {
            foreach (self::PERMISSIONS as $permission) {
                if (! Schema::hasColumn('tenant_roles', $permission)) {
                    $table->boolean($permission)->default(false);
                }
            }
        });

        Schema::table('tenant_user_permissions', function (Blueprint $table): void {
            foreach (self::PERMISSIONS as $permission) {
                if (! Schema::hasColumn('tenant_user_permissions', $permission)) {
                    $table->boolean($permission)->default(false);
                }
            }
        });

        Schema::table('pawn_loan_contract_slips', function (Blueprint $table): void {
            if (! Schema::hasColumn('pawn_loan_contract_slips', 'compound_schedule_enabled')) {
                $table->boolean('compound_schedule_enabled')->default(false)->after('expiry_quota_type')->index();
            }
            if (! Schema::hasColumn('pawn_loan_contract_slips', 'compound_every')) {
                $table->unsignedInteger('compound_every')->nullable()->after('compound_schedule_enabled');
            }
            if (! Schema::hasColumn('pawn_loan_contract_slips', 'compound_every_type')) {
                $table->string('compound_every_type', 20)->nullable()->after('compound_every');
            }
            if (! Schema::hasColumn('pawn_loan_contract_slips', 'next_compound_at')) {
                $table->timestamp('next_compound_at')->nullable()->after('compound_every_type')->index();
            }
            if (! Schema::hasColumn('pawn_loan_contract_slips', 'last_compounded_at')) {
                $table->timestamp('last_compounded_at')->nullable()->after('next_compound_at');
            }
        });

        DB::table('tenant_roles')
            ->whereIn('name', ['Owner', 'Admin'])
            ->update(array_fill_keys(self::PERMISSIONS, true));

        $feature = Feature::query()->updateOrCreate(
            ['code' => 'advanced_interest_process'],
            [
                'name' => 'Advanced interest process',
                'description' => 'Configure interest compounding and partial principal collection.',
                'is_active' => true,
            ],
        );

        Package::query()->each(function (Package $package) use ($feature): void {
            PackageFeature::query()->updateOrCreate(
                ['package_id' => $package->id, 'feature_id' => $feature->id],
                ['is_enabled' => $package->code === 'premium'],
            );
        });
    }

    public function down(): void
    {
        Schema::table('pawn_loan_contract_slips', function (Blueprint $table): void {
            foreach (['last_compounded_at', 'next_compound_at', 'compound_every_type', 'compound_every', 'compound_schedule_enabled'] as $column) {
                if (Schema::hasColumn('pawn_loan_contract_slips', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('tenant_user_permissions', function (Blueprint $table): void {
            foreach (self::PERMISSIONS as $permission) {
                if (Schema::hasColumn('tenant_user_permissions', $permission)) {
                    $table->dropColumn($permission);
                }
            }
        });

        Schema::table('tenant_roles', function (Blueprint $table): void {
            foreach (self::PERMISSIONS as $permission) {
                if (Schema::hasColumn('tenant_roles', $permission)) {
                    $table->dropColumn($permission);
                }
            }
        });
    }
};
