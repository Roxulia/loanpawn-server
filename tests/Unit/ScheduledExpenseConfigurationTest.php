<?php

namespace Tests\Unit;

use App\DataObjects\RequestObjects\TenantScheduledExpenseWrite;
use App\Exceptions\InvalidTenantRequest;
use App\Jobs\ProcessScheduledExpensesJob;
use App\Models\CoreModule\TenantScheduledExpense;
use App\Services\TenantModule\TenantScheduledExpenseService;
use Carbon\CarbonImmutable;
use ReflectionClass;
use Tests\TestCase;

class ScheduledExpenseConfigurationTest extends TestCase
{
    public function test_feature_is_active_and_premium_only(): void
    {
        $this->assertSame('SE', config('code_generation.prefixes.tenant_scheduled_expenses'));
        $this->assertTrue(config('package_features.features.scheduled_expense_management.is_active'));
        $this->assertContains('scheduled_expense_management', config('package_features.packages.premium.features'));
        $this->assertNotContains('scheduled_expense_management', config('package_features.packages.basic.features'));
        $this->assertNotContains('scheduled_expense_management', config('package_features.packages.trial.features'));
    }

    public function test_updated_schedule_must_start_after_tenant_local_time(): void
    {
        $service = (new ReflectionClass(TenantScheduledExpenseService::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass($service))->getMethod('ensureFutureStart');
        $request = new TenantScheduledExpenseWrite(
            description: 'Rent', amount: 1000, accountId: 1, expenseTypeId: null,
            recurrenceType: 'one_time', startDate: '2026-09-12', scheduledTime: '10:00',
            endDate: null, weeklyDay: null, monthlyAnchorDay: null,
        );

        $this->expectException(InvalidTenantRequest::class);
        $method->invoke($service, $request, CarbonImmutable::parse('2026-09-12 10:00:00', 'Asia/Yangon'));
    }

    public function test_updated_schedule_can_start_later_today(): void
    {
        $service = (new ReflectionClass(TenantScheduledExpenseService::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass($service))->getMethod('ensureFutureStart');
        $request = new TenantScheduledExpenseWrite(
            description: 'Rent', amount: 1000, accountId: 1, expenseTypeId: null,
            recurrenceType: 'one_time', startDate: '2026-09-12', scheduledTime: '10:01',
            endDate: null, weeklyDay: null, monthlyAnchorDay: null,
        );

        $method->invoke($service, $request, CarbonImmutable::parse('2026-09-12 10:00:00', 'Asia/Yangon'));
        $this->addToAssertionCount(1);
    }

    public function test_permissions_and_queue_are_registered(): void
    {
        foreach (['list', 'create', 'update', 'delete'] as $action) {
            $this->assertArrayHasKey("{$action}_scheduled_expense", config('tenant_permissions.codes'));
        }
        $this->assertSame('scheduled', (new ProcessScheduledExpensesJob)->queue);
    }

    public function test_monthly_recurrence_clamps_to_month_end_and_keeps_anchor(): void
    {
        $service = (new ReflectionClass(TenantScheduledExpenseService::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass($service))->getMethod('nextDate');
        $schedule = new TenantScheduledExpense([
            'recurrence_type' => 'monthly',
            'monthly_anchor_day' => 31,
            'end_date' => '2026-04-30',
        ]);

        $february = $method->invoke($service, $schedule, CarbonImmutable::parse('2026-01-31'));
        $march = $method->invoke($service, $schedule, $february);

        $this->assertSame('2026-02-28', $february->toDateString());
        $this->assertSame('2026-03-31', $march->toDateString());
    }
}
