<?php

namespace Tests\Unit;

use App\Enums\AccountingDayStatus;
use App\Models\TenantAccountingDay;
use App\Models\TenantAccountingDaySchedule;
use App\Repository\TenantAccountingDayRepository;
use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use App\Services\TenantModule\AccountingDayBusinessClock;
use App\Services\TenantModule\TenantAccountingDayService;
use App\Services\TenantModule\TenantUserPermissionService;
use Carbon\CarbonImmutable;
use Mockery;
use Tests\TestCase;

class TenantAccountingDayEligibilityTest extends TestCase
{
    public function test_open_manual_day_allows_scheduled_operation_without_weekday_schedule(): void
    {
        $now = CarbonImmutable::parse('2026-09-18 10:00:00', 'Asia/Yangon');
        $repository = Mockery::mock(TenantAccountingDayRepository::class);
        $repository->shouldReceive('scheduleForWeekday')->with(7, $now->dayOfWeek)->andReturnNull();
        $repository->shouldReceive('findForTenantDate')->with(7, '2026-09-18')->andReturn($this->openDay());

        $this->assertTrue($this->service($repository)->allowsScheduledFinancialOperation(7, $now));
    }

    public function test_open_manual_day_allows_scheduled_operation_with_disabled_weekday_schedule(): void
    {
        $now = CarbonImmutable::parse('2026-09-18 10:00:00', 'Asia/Yangon');
        $repository = Mockery::mock(TenantAccountingDayRepository::class);
        $repository->shouldReceive('scheduleForWeekday')->with(7, $now->dayOfWeek)->andReturn(new TenantAccountingDaySchedule([
            'is_enabled' => false,
            'open_time' => '09:00:00',
            'close_time' => '17:00:00',
        ]));
        $repository->shouldReceive('findForTenantDate')->with(7, '2026-09-18')->andReturn($this->openDay());

        $this->assertTrue($this->service($repository)->allowsScheduledFinancialOperation(7, $now));
    }

    public function test_enabled_weekday_schedule_still_blocks_operations_outside_its_interval(): void
    {
        $now = CarbonImmutable::parse('2026-09-18 18:00:00', 'Asia/Yangon');
        $repository = Mockery::mock(TenantAccountingDayRepository::class);
        $repository->shouldReceive('scheduleForWeekday')->with(7, $now->dayOfWeek)->andReturn(new TenantAccountingDaySchedule([
            'is_enabled' => true,
            'open_time' => '09:00:00',
            'close_time' => '17:00:00',
        ]));
        $repository->shouldReceive('findForTenantDate')->with(7, '2026-09-18')->andReturn($this->openDay());

        $this->assertFalse($this->service($repository)->allowsScheduledFinancialOperation(7, $now));
    }

    private function service(TenantAccountingDayRepository $repository): TenantAccountingDayService
    {
        $license = Mockery::mock(TenantLicenseService::class);
        $license->shouldReceive('tenantHasFeature')->with(7, 'automatic_open_close')->andReturnTrue();

        return new TenantAccountingDayService(
            $repository,
            Mockery::mock(AccountingDayBusinessClock::class),
            Mockery::mock(TenantUserPermissionService::class),
            $license,
        );
    }

    private function openDay(): TenantAccountingDay
    {
        return new TenantAccountingDay(['status' => AccountingDayStatus::Open]);
    }
}
