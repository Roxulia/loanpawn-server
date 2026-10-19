<?php

namespace Tests\Feature\TenantModule;

use App\DataObjects\RequestObjects\InventoryReceiveRequest;
use App\Exceptions\InvalidTenantRequest;
use App\Jobs\SummarizeMonthlyInventoryMovementsJob;
use App\Repository\InventoryRepository;
use App\Services\TenantModule\{InventoryMonthlySummaryService, InventoryService};
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommittedInventoryFixture;

class InventoryMonthlySummaryTest extends CommittedInventoryFixture
{
    public function test_real_summary_reuses_previous_checkpoint_and_is_idempotent_without_version_changes(): void
    {
        $summary = app(InventoryMonthlySummaryService::class);
        $versions = $this->versions();
        $this->assertSame(2, $summary->summarizeTenant($this->fixtureTenant->id, '2026-10'));
        $this->assertSame(0, $summary->summarizeTenant($this->fixtureTenant->id, '2026-10'));
        $this->assertSame($versions, $this->versions());
        $item = $this->fixtureItems['QUANTITY']['inventory_item_code'];
        app(InventoryService::class)->receive(new InventoryReceiveRequest(locationCode: $this->fixtureLocation, quantity: '0.001', inventoryItemCode: $item));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-02', 'UTC'));
        $this->assertSame(2, $summary->summarizeTenant($this->fixtureTenant->id, '2026-11'));
        $this->assertSame('1.001', app(InventoryService::class)->itemDetail($item)->totalQuantity);
        $header = DB::table('inventory_monthly_checkpoints')->where('tenant_id', $this->fixtureTenant->id)->where('month', '2026-11-01')
            ->where('inventory_item_id', app(InventoryService::class)->findForOwnershipByCode($item)->id)->sole();
        $this->assertSame('1.001', (string) DB::table('inventory_monthly_checkpoint_balances')->where('inventory_monthly_checkpoint_id', $header->id)->value('quantity'));
    }

    public function test_missing_previous_month_and_backdated_business_movement_preserve_full_ledger_totals(): void
    {
        $service = app(InventoryService::class);
        $item = $service->findForOwnershipByCode($this->fixtureItems['QUANTITY']['inventory_item_code']);
        // Exercise the repository append contract: recording time overrides caller timestamps.
        DB::transaction(function () use ($item): void {
            app(InventoryRepository::class)->createMovement(['code' => 'backdated-'.bin2hex(random_bytes(4)),
                'inventory_item_id' => $item->id, 'to_location_id' => DB::table('inventory_locations')->where('tenant_id', $this->fixtureTenant->id)->value('id'),
                'movement_type' => 'RECEIVE', 'quantity' => '0.001', 'occurred_at' => '2026-09-01 00:00:00',
                'created_at' => '2026-09-01 00:00:00']);
        });
        $summary = app(InventoryMonthlySummaryService::class);
        $this->assertSame(2, $summary->summarizeTenant($this->fixtureTenant->id, '2026-10'));
        $this->assertSame('1.001', $service->itemDetail($item->code)->totalQuantity);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-02', 'UTC'));
        // Skip November: December publication must use October plus the longer live tail.
        $this->assertSame(2, $summary->summarizeTenant($this->fixtureTenant->id, '2026-12'));
        $this->assertSame('1.001', $service->itemDetail($item->code)->totalQuantity);
        $this->assertSame('1.001', (string) DB::table('inventory_movements')->where('tenant_id', $this->fixtureTenant->id)->where('inventory_item_id', $item->id)->sum('quantity'));
    }

    public function test_failed_second_item_has_no_partial_checkpoint_and_resume_skips_completed_item(): void
    {
        $armed = true;
        $headers = 0;
        DB::listen(static function (QueryExecuted $query) use (&$armed, &$headers): void {
            if (! $armed) return;
            if (str_starts_with($query->sql, 'insert into `inventory_monthly_checkpoints`')) ++$headers;
            if ($headers === 2 && str_starts_with($query->sql, 'insert into `inventory_monthly_checkpoint_balances`')) throw new \RuntimeException('Injected second-item checkpoint failure');
        });
        $context = app(TenantContext::class);
        $context->set(999999999);
        try {
            app(InventoryMonthlySummaryService::class)->summarizeTenant($this->fixtureTenant->id, '2026-10');
            $this->fail('The injected fault must stop publication.');
        } catch (\RuntimeException $exception) { $this->assertSame('Injected second-item checkpoint failure', $exception->getMessage()); }
        finally { $armed = false; }
        $this->assertSame($context, app(TenantContext::class));
        $this->assertSame(999999999, $context->id());
        $this->assertSame(1, DB::table('inventory_monthly_checkpoints')->where('tenant_id', $this->fixtureTenant->id)->count());
        $this->assertSame(1, app(InventoryMonthlySummaryService::class)->summarizeTenant($this->fixtureTenant->id, '2026-10'));
        $this->assertSame(2, DB::table('inventory_monthly_checkpoints')->where('tenant_id', $this->fixtureTenant->id)->count());
    }

    public function test_timezone_cutoff_is_pinned_and_context_is_restored_on_success(): void
    {
        DB::table('tenant_settings')->where('tenant_id', $this->fixtureTenant->id)->where('key', 'timezone')->update(['value' => 'Asia/Yangon']);
        $summary = app(InventoryMonthlySummaryService::class);
        $original = app(TenantContext::class);
        $original->set(999999999);
        $this->assertSame(2, $summary->summarizeTenant($this->fixtureTenant->id, '2026-10'));
        $this->assertSame($original, app(TenantContext::class));
        $this->assertSame(999999999, app(TenantContext::class)->id());
        $header = DB::table('inventory_monthly_checkpoints')->where('tenant_id', $this->fixtureTenant->id)->first();
        $this->assertSame('2026-10-31 17:30:00', $header->cutoff_at);
        DB::table('tenant_settings')->where('tenant_id', $this->fixtureTenant->id)->where('key', 'timezone')->update(['value' => 'America/Los_Angeles']);
        $this->assertSame(0, $summary->summarizeTenant($this->fixtureTenant->id, '2026-10'));
        $this->assertSame('2026-10-31 17:30:00', DB::table('inventory_monthly_checkpoints')->where('id', $header->id)->value('cutoff_at'));
    }

    #[DataProvider('invalidMonths')]
    public function test_malformed_current_and_future_months_do_not_publish(string $month): void
    {
        try { app(InventoryMonthlySummaryService::class)->summarizeTenant($this->fixtureTenant->id, $month); $this->fail('Month must be rejected.'); }
        catch (InvalidTenantRequest) { $this->assertSame(0, DB::table('inventory_monthly_checkpoints')->where('tenant_id', $this->fixtureTenant->id)->count()); }
    }

    public static function invalidMonths(): array
    {
        return [['2026-13'], ['2026-1'], ['invalid'], ['2026-11'], ['2027-01']];
    }

    public function test_real_recovery_command_and_tenant_job_share_idempotent_publication(): void
    {
        $job = new SummarizeMonthlyInventoryMovementsJob('2026-10', $this->fixtureTenant->id);
        app()->call([$job, 'handle']);
        $this->assertSame(2, DB::table('inventory_monthly_checkpoints')->where('tenant_id', $this->fixtureTenant->id)->count());
        $this->artisan('inventory:summarize-monthly', ['--tenant' => $this->fixtureTenant->id, '--month' => '2026-10'])
            ->expectsOutput('Published 0 Inventory item checkpoint(s).')->assertSuccessful();
        app()->call([$job, 'handle']);
        $this->assertSame(2, DB::table('inventory_monthly_checkpoints')->where('tenant_id', $this->fixtureTenant->id)->count());
        $this->assertSame('scheduled', $job->queue);
        $this->assertCount(1, $job->middleware());
    }

    public function test_root_job_pins_month_before_dispatch_and_schedule_is_registered(): void
    {
        // Limit tenant discovery to this fixture; fake dispatch prevents touching unrelated tenants.
        \Illuminate\Support\Facades\Bus::fake();
        $registry = \Mockery::mock(\App\Repository\TenantSettingRepository::class);
        $registry->shouldReceive('allTenantIds')->once()->andReturn(collect([$this->fixtureTenant->id]));
        $job = new SummarizeMonthlyInventoryMovementsJob();
        $job->handle(app(InventoryMonthlySummaryService::class), $registry);
        \Illuminate\Support\Facades\Bus::assertDispatched(SummarizeMonthlyInventoryMovementsJob::class,
            fn ($child) => $child->tenantId === $this->fixtureTenant->id && $child->month === '2026-10' && $child->queue === 'scheduled');
        // ApplicationBuilder registers schedules when the Artisan application starts.
        app(\Illuminate\Contracts\Console\Kernel::class)->all();
        $events = app(\Illuminate\Console\Scheduling\Schedule::class)->events();
        $event = collect($events)->first(fn ($event) => str_contains($event->description ?? '', SummarizeMonthlyInventoryMovementsJob::class));
        $this->assertNotNull($event);
        $this->assertSame('45 0 2 * *', $event->expression);
        $this->assertSame('UTC', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }
    private function versions(): array
    {
        return DB::table('inventory_items')->where('tenant_id', $this->fixtureTenant->id)->orderBy('id')->pluck('update_key', 'id')->all();
    }

}
