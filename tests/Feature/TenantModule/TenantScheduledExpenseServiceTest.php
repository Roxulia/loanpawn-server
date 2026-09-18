<?php

namespace Tests\Feature\TenantModule;

use App\DataObjects\RequestObjects\TenantScheduledExpenseWrite;
use App\DataObjects\ResponseObjects\TenantExpenseDetail;
use App\Enums\AccountingDayOpeningSource;
use App\Enums\AccountingDayStatus;
use App\Models\CoreModule\Currency;
use App\Models\CoreModule\TenantExpense;
use App\Models\CoreModule\TenantScheduledExpense;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountTypes;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Models\TenantAccountingDay;
use App\Repository\TenantScheduledExpenseRepository;
use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use App\Services\TableIdGenerationService;
use App\Services\TenantModule\Accounting\MultiAccountManagement;
use App\Services\TenantModule\AccountingDayBusinessClock;
use App\Services\TenantModule\TenantAccountingDayService;
use App\Services\TenantModule\TenantExpenseService;
use App\Services\TenantModule\TenantScheduledExpenseService;
use App\Services\TenantModule\TenantUserPermissionService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TenantScheduledExpenseServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_due_one_time_schedule_creates_exactly_one_expense(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12 10:00:00', 'Asia/Yangon'));
        [$tenant, $account] = $this->financeContext();
        $this->assertSame($account->id, app(MultiAccountManagement::class)->findActiveCurrentTenantAccountForSystem($account->id)->id);
        $schedule = $this->schedule($tenant->id, $account->id);

        $license = Mockery::mock(TenantLicenseService::class);
        $license->shouldReceive('tenantHasFeature')->with($tenant->id, 'scheduled_expense_management')->andReturnTrue();
        $this->app->instance(TenantLicenseService::class, $license);
        $accountingDay = Mockery::mock(TenantAccountingDayService::class);
        $accountingDay->shouldReceive('allowsScheduledFinancialOperation')->andReturnTrue();
        $this->app->instance(TenantAccountingDayService::class, $accountingDay);
        $expenses = Mockery::mock(TenantExpenseService::class);
        $expenses->shouldReceive('createFromSchedule')->once()->andReturnUsing(function ($request) use ($tenant, $account): TenantExpenseDetail {
            $expense = TenantExpense::query()->create(['tenant_id' => $tenant->id, 'code' => 'AUTO-1', 'account_id' => $account->id,
                'description' => $request->description, 'amount' => $request->amount, 'expense_type_id' => null, 'created_by' => null]);

            return TenantExpenseDetail::fromModel($expense);
        });
        $this->app->instance(TenantExpenseService::class, $expenses);

        $this->assertSame(1, app(TenantScheduledExpenseService::class)->processDueSchedules());
        $this->assertSame(0, app(TenantScheduledExpenseService::class)->processDueSchedules());
        $this->assertDatabaseHas('tenant_scheduled_expense_occurrences', ['scheduled_expense_id' => $schedule->id, 'status' => 'paid']);
        $this->assertDatabaseHas('tenant_scheduled_expenses', ['id' => $schedule->id, 'status' => 'completed']);
    }

    public function test_due_schedule_is_deferred_when_accounting_interval_is_closed(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12 10:00:00', 'Asia/Yangon'));
        [$tenant, $account] = $this->financeContext();
        $schedule = $this->schedule($tenant->id, $account->id);
        $license = Mockery::mock(TenantLicenseService::class);
        $license->shouldReceive('tenantHasFeature')->andReturnTrue();
        $this->app->instance(TenantLicenseService::class, $license);
        $accountingDay = Mockery::mock(TenantAccountingDayService::class);
        $accountingDay->shouldReceive('allowsScheduledFinancialOperation')->andReturnFalse();
        $this->app->instance(TenantAccountingDayService::class, $accountingDay);
        $expenses = Mockery::mock(TenantExpenseService::class);
        $expenses->shouldNotReceive('createFromSchedule');
        $this->app->instance(TenantExpenseService::class, $expenses);

        $this->assertSame(0, app(TenantScheduledExpenseService::class)->processDueSchedules());
        $this->assertDatabaseHas('tenant_scheduled_expense_occurrences', ['scheduled_expense_id' => $schedule->id, 'status' => 'pending']);
    }

    public function test_open_manual_accounting_day_processes_due_schedule_when_automatic_schedule_is_disabled(): void
    {
        $now = CarbonImmutable::parse('2026-09-12 10:00:00', 'Asia/Yangon');
        CarbonImmutable::setTestNow($now);
        [$tenant, $account] = $this->financeContext();
        $schedule = $this->schedule($tenant->id, $account->id);
        TenantAccountingDay::query()->create([
            'tenant_id' => $tenant->id,
            'business_date' => $now->toDateString(),
            'timezone' => $now->timezoneName,
            'status' => AccountingDayStatus::Open,
            'opened_at' => $now->utc(),
            'opening_source' => AccountingDayOpeningSource::Manual,
        ]);

        $license = Mockery::mock(TenantLicenseService::class);
        $license->shouldReceive('tenantHasFeature')
            ->with($tenant->id, 'scheduled_expense_management')
            ->andReturnTrue();
        $license->shouldReceive('tenantHasFeature')
            ->with($tenant->id, 'automatic_open_close')
            ->andReturnTrue();
        $this->app->instance(TenantLicenseService::class, $license);

        $expenses = Mockery::mock(TenantExpenseService::class);
        $expenses->shouldReceive('createFromSchedule')->once()->andReturnUsing(function ($request) use ($tenant, $account): TenantExpenseDetail {
            $expense = TenantExpense::query()->create([
                'tenant_id' => $tenant->id,
                'code' => 'AUTO-MANUAL-DAY',
                'account_id' => $account->id,
                'description' => $request->description,
                'amount' => $request->amount,
                'expense_type_id' => null,
                'created_by' => null,
            ]);

            return TenantExpenseDetail::fromModel($expense);
        });
        $this->app->instance(TenantExpenseService::class, $expenses);

        $this->assertSame(1, app(TenantScheduledExpenseService::class)->processDueSchedules());
        $this->assertDatabaseHas('tenant_scheduled_expense_occurrences', [
            'scheduled_expense_id' => $schedule->id,
            'status' => 'paid',
        ]);
    }

    public function test_amount_only_update_does_not_revalidate_or_reset_past_schedule_boundary(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12 10:00:00', 'Asia/Yangon'));
        [$tenant, $account] = $this->financeContext();
        $schedule = $this->schedule($tenant->id, $account->id);

        $permissions = Mockery::mock(TenantUserPermissionService::class);
        $permissions->shouldReceive('authorizePermission')->once()->with('update_scheduled_expense');
        $clock = Mockery::mock(AccountingDayBusinessClock::class);
        $clock->shouldNotReceive('now');
        $accounts = Mockery::mock(MultiAccountManagement::class);
        $accounts->shouldNotReceive('findActiveCurrentTenantAccount');

        $service = new TenantScheduledExpenseService(
            app(TenantScheduledExpenseRepository::class),
            $permissions,
            Mockery::mock(TenantLicenseService::class),
            $clock,
            Mockery::mock(TenantAccountingDayService::class),
            Mockery::mock(TenantExpenseService::class),
            $accounts,
            Mockery::mock(TableIdGenerationService::class),
        );
        $result = $service->update($schedule->code, new TenantScheduledExpenseWrite(
            description: 'Rent', amount: 2500, accountId: $account->id, expenseTypeId: null,
            recurrenceType: 'one_time', startDate: '2026-09-12', scheduledTime: '09:00',
            endDate: null, weeklyDay: null, monthlyAnchorDay: null, updateKey: 0,
        ))->toArray();

        $this->assertSame('2500.00', $result['amount']);
        $this->assertSame(1, $result['update_key']);
        $this->assertSame('2026-09-12', $result['next_due_date']);
        $this->assertSame('active', $result['status']);
    }

    public function test_payment_failure_is_recorded_on_occurrence_and_schedule(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12 10:00:00', 'Asia/Yangon'));
        [$tenant, $account] = $this->financeContext();
        $schedule = $this->schedule($tenant->id, $account->id);
        $license = Mockery::mock(TenantLicenseService::class);
        $license->shouldReceive('tenantHasFeature')->andReturnTrue();
        $this->app->instance(TenantLicenseService::class, $license);
        $accountingDay = Mockery::mock(TenantAccountingDayService::class);
        $accountingDay->shouldReceive('allowsScheduledFinancialOperation')->andReturnTrue();
        $this->app->instance(TenantAccountingDayService::class, $accountingDay);
        $expenses = Mockery::mock(TenantExpenseService::class);
        $expenses->shouldReceive('createFromSchedule')->once()->andThrow(new \RuntimeException('Insufficient account balance.'));
        $this->app->instance(TenantExpenseService::class, $expenses);

        $this->assertSame(0, app(TenantScheduledExpenseService::class)->processDueSchedules());
        $this->assertDatabaseHas('tenant_scheduled_expense_occurrences', [
            'scheduled_expense_id' => $schedule->id,
            'status' => 'failed',
            'attempt_count' => 1,
            'last_error' => 'Insufficient account balance.',
        ]);
        $this->assertDatabaseHas('tenant_scheduled_expenses', [
            'id' => $schedule->id,
            'last_result' => 'failed',
            'last_error' => 'Insufficient account balance.',
        ]);
        $this->assertDatabaseCount('tenant_expenses', 0);
    }

    private function financeContext(): array
    {
        $owner = PlatformUser::query()->create(['code' => 'PU00000001', 'name' => 'Owner', 'email' => 'schedule@example.com', 'phone' => '09111111111', 'password' => 'secret123', 'status' => 'active']);
        $tenant = Tenant::query()->create(['platform_user_id' => $owner->id, 'name' => 'Schedule Tenant', 'tenant_code' => 'schedule-tenant', 'subdomain' => 'schedule-tenant', 'status' => 'active']);
        $currency = Currency::query()->create(['tenant_id' => null, 'scope_key' => 'system', 'code' => 'MMK', 'name' => 'Kyat', 'symbol' => 'K', 'is_default' => true]);
        $type = FinancialAccountTypes::query()->create(['tenant_id' => $tenant->id, 'code' => 'cash', 'name' => 'Cash', 'is_active' => true]);
        $account = FinancialAccount::query()->create(['tenant_id' => $tenant->id, 'account_type_id' => $type->id, 'currency_id' => $currency->id,
            'account_number' => 'CASH-1', 'account_name' => 'Cash', 'account_code' => 'cash-1', 'balance' => 100000, 'is_active' => true, 'is_default' => true, 'allow_negative_balance' => false]);
        app(TenantContext::class)->set($tenant);

        return [$tenant, $account];
    }

    private function schedule(int $tenantId, int $accountId): TenantScheduledExpense
    {
        return TenantScheduledExpense::query()->create(['tenant_id' => $tenantId, 'code' => 'SE-1', 'account_id' => $accountId,
            'description' => 'Rent', 'amount' => 1000, 'recurrence_type' => 'one_time', 'start_date' => '2026-09-12',
            'scheduled_time' => '09:00', 'next_due_date' => '2026-09-12', 'status' => 'active']);
    }
}
