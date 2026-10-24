<?php

namespace Tests\Feature\TenantModule;

use App\Jobs\SummarizeMonthlyOwnershipMovementsJob;
use App\Repository\TenantSettingRepository;
use App\Services\TenantModule\OwnershipMonthlySummaryService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Tests\Support\OwnershipMonthlyTestCase;

class OwnershipMonthlyJobTest extends OwnershipMonthlyTestCase
{
    public function test_root_pins_month_and_dispatches_one_tenant_child(): void
    {
        // Only tenant discovery and dispatch are controlled; real month resolution uses the fixture's
        // timezone. This does not claim queue delivery or publication occurred under Bus::fake.
        $this->clock('2026-11-15 12:00:00');
        Bus::fake();
        $registry = \Mockery::mock(TenantSettingRepository::class);
        $tenant = (int) $this->ownershipFixture['tenant']->id;
        $registry->shouldReceive('streamTenantIds')->once()->andReturn(collect([$tenant]));
        $job = new SummarizeMonthlyOwnershipMovementsJob();
        $job->handle(app(OwnershipMonthlySummaryService::class), $registry);
        Bus::assertDispatched(SummarizeMonthlyOwnershipMovementsJob::class,
            fn ($child) => $child->tenantId === $tenant && $child->month === '2026-10' && $child->queue === 'scheduled');
        Bus::assertDispatchedTimes(SummarizeMonthlyOwnershipMovementsJob::class, 1);
    }

    public function test_tenant_job_and_recovery_command_share_publication(): void
    {
        $this->linkedGoods();
        $this->clock('2026-11-15 12:00:00');
        $tenant = (int) $this->ownershipFixture['tenant']->id;
        $job = new SummarizeMonthlyOwnershipMovementsJob('2026-10', $tenant);
        app()->call([$job, 'handle']);
        $saved = $this->checkpoints();
        $this->assertCount(1, $saved['ownership_monthly_checkpoints']);
        $this->artisan('ownership:summarize-monthly', ['--tenant' => $tenant, '--month' => '2026-10'])->assertSuccessful();
        app()->call([$job, 'handle']);
        $this->assertSame($saved, $this->checkpoints());
        $this->assertSame('scheduled', $job->queue);
        $this->assertSame(app(\App\Support\RedisAvailability::class)->selectedQueueConnection(), $job->connection);
        $this->assertCount(1, $job->middleware());
        $this->assertInstanceOf(\Illuminate\Queue\Middleware\WithoutOverlapping::class, $job->middleware()[0]);
    }

    public function test_monthly_schedule_is_registered_once_with_overlap_guard(): void
    {
        // Inspect registered events without starting a scheduler, dispatching work, or running commands.
        app(Kernel::class)->all();
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) =>
            str_contains($event->description ?? '', SummarizeMonthlyOwnershipMovementsJob::class));
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('UTC', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertMatchesRegularExpression('/^\d+ \d+ 2 \* \*$/', $event->expression);
    }

    public function test_job_logs_success_and_failure_using_existing_logger(): void
    {
        // Logging is the only mocked boundary. Both calls run the actual job and real summary
        // validation; an invalid month must be logged and rethrown, never swallowed as success.
        $this->clock('2026-11-15 12:00:00');
        $channel = \Mockery::mock(\Psr\Log\LoggerInterface::class);
        Log::shouldReceive('channel')->with('services')->twice()->andReturn($channel);
        $channel->shouldReceive('info')->once()->with('Service operation completed.', \Mockery::on(
            fn ($context) => $context['operation'] === SummarizeMonthlyOwnershipMovementsJob::class.'::handle'));
        $channel->shouldReceive('error')->once()->with('Service operation failed.', \Mockery::on(
            fn ($context) => $context['exception'] === \App\Exceptions\InvalidTenantRequest::class));
        $tenant = (int) $this->ownershipFixture['tenant']->id;
        app()->call([new SummarizeMonthlyOwnershipMovementsJob('2026-10', $tenant), 'handle']);
        $this->expectException(\App\Exceptions\InvalidTenantRequest::class);
        app()->call([new SummarizeMonthlyOwnershipMovementsJob('invalid', $tenant), 'handle']);
    }
}
