<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\CarbonImmutable;

return new class extends Migration
{
    private array $permissions = ['manage_debt_compound_schedule', 'compound_debt_interest'];

    public function up(): void
    {
        foreach (['tenant_roles', 'tenant_user_permissions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                foreach ($this->permissions as $permission) {
                    if (! Schema::hasColumn($table->getTable(), $permission)) {
                        $table->boolean($permission)->default(false);
                    }
                }
            });
        }

        Schema::table('tenant_debts', function (Blueprint $table): void {
            $table->boolean('compound_schedule_enabled')->default(false)->index();
            $table->unsignedInteger('compound_every')->nullable();
            $table->string('compound_every_type', 20)->nullable();
            $table->timestamp('next_compound_at')->nullable()->index();
            $table->timestamp('last_compounded_at')->nullable();
        });

        Schema::table('tenant_debt_interest_accruals', function (Blueprint $table): void {
            $table->decimal('compounded_amount', 14, 2)->default(0)->after('paid_amount');
            $table->timestamp('compounded_at')->nullable()->after('compounded_amount');
            $table->string('period_timezone', 64)->nullable()->after('end_period_at');
        });

        Schema::table('pawn_interest_payments', function (Blueprint $table): void {
            $table->string('period_timezone', 64)->nullable()->after('end_period_at');
        });

        $timezones = DB::table('tenant_settings')->where('key', 'timezone')->pluck('value', 'tenant_id');
        foreach (['pawn_interest_payments', 'tenant_debt_interest_accruals'] as $tableName) {
            DB::table($tableName)->whereNotNull('start_period_at')->whereNotNull('end_period_at')->orderBy('id')->chunkById(200, function ($rows) use ($tableName, $timezones): void {
                foreach ($rows as $row) {
                    $timezone = (string) ($timezones[$row->tenant_id] ?? 'Asia/Yangon');
                    if (! in_array($timezone, timezone_identifiers_list(), true)) $timezone = 'Asia/Yangon';
                    $startDate = CarbonImmutable::parse($row->start_period_at, 'UTC')->format('Y-m-d');
                    $endDate = CarbonImmutable::parse($row->end_period_at, 'UTC')->format('Y-m-d');
                    DB::table($tableName)->where('id', $row->id)->update([
                        'start_period_at' => CarbonImmutable::parse($startDate, $timezone)->startOfDay()->utc(),
                        'end_period_at' => CarbonImmutable::parse($endDate, $timezone)->endOfDay()->setMicrosecond(0)->utc(),
                        'period_timezone' => $timezone,
                    ]);
                }
            });
        }

        DB::table('tenant_roles')->whereIn('name', ['Owner', 'Admin'])->update(array_fill_keys($this->permissions, true));
    }

    public function down(): void
    {
        Schema::table('pawn_interest_payments', fn (Blueprint $table) => $table->dropColumn('period_timezone'));
        Schema::table('tenant_debt_interest_accruals', fn (Blueprint $table) => $table->dropColumn(['compounded_amount', 'compounded_at', 'period_timezone']));
        Schema::table('tenant_debts', fn (Blueprint $table) => $table->dropColumn(['compound_schedule_enabled', 'compound_every', 'compound_every_type', 'next_compound_at', 'last_compounded_at']));
        foreach (['tenant_roles', 'tenant_user_permissions'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($this->permissions));
        }
    }
};
