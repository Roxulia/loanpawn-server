<?php

namespace Tests\Feature\TenantModule;

use App\DataObjects\RequestObjects\HistoricalRateBackfillRequest;
use App\DataObjects\RequestObjects\StoreExchangeRateRequest;
use App\Exceptions\InvalidTenantRequest;
use App\Jobs\RecalculateReportingCurrencyJob;
use App\Models\CoreModule\Currency;
use App\Models\CoreModule\DailyExchangeRateSummary;
use App\Models\CoreModule\TenantSetting;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Models\ReportingCurrencyRecalculation;
use App\Services\TenantModule\Accounting\HistoricalRateBackfillService;
use App\Services\TenantModule\Accounting\ReportingCurrencyRecalculationService;
use App\Services\TenantModule\TenantExchangeRateService;
use App\Services\ExchangeRate\ExchangeRateBusinessClock;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ExchangeRatePairSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Mockery;

class ReportingCurrencyHistoricalRateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $clock = Mockery::mock(ExchangeRateBusinessClock::class);
        $clock->shouldReceive('now')->andReturn(CarbonImmutable::parse('2026-08-15 12:00:00', 'Asia/Yangon'));
        $this->app->instance(ExchangeRateBusinessClock::class, $clock);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_tenant_without_requested_recalculation_cannot_open_backfill(): void
    {
        $tenant = $this->tenant('no-request');
        app(TenantContext::class)->set($tenant->id);

        $this->expectException(InvalidTenantRequest::class);
        app(HistoricalRateBackfillService::class)->requirements();
    }

    public function test_requested_historical_rates_are_saved_once_as_open_and_close(): void
    {
        Queue::fake([RecalculateReportingCurrencyJob::class]);
        [$tenant, $recalculation] = $this->waitingRecalculation('backfill');
        app(TenantContext::class)->set($tenant->id);
        $service = app(HistoricalRateBackfillService::class);
        $requirements = $service->requirements();
        $requirement = $requirements->requirements[0];

        $response = $service->submit(new HistoricalRateBackfillRequest($recalculation->id, [[
            'requirement_key' => $requirement['requirement_key'],
            'buying_open' => '3500',
            'buying_close' => '3510',
            'selling_open' => '3520',
            'selling_close' => '3530',
        ]]));

        $this->assertSame([], $response->requirements);
        $this->assertDatabaseCount('exchange_rate_entries', 2);
        $summary = DailyExchangeRateSummary::query()->firstOrFail();
        $this->assertSame($tenant->id, $summary->tenant_id);
        $this->assertSame('2026-07-15', $summary->rate_date->toDateString());
        $this->assertSame('3500.000000000000', $summary->buying_open);
        $this->assertSame('3510.000000000000', $summary->buying_close);
        $this->assertSame('3520.000000000000', $summary->selling_open);
        $this->assertSame('3530.000000000000', $summary->selling_close);
        $this->assertSame('queued', $recalculation->refresh()->status);
        Queue::assertPushed(RecalculateReportingCurrencyJob::class, 1);

        $this->expectException(InvalidTenantRequest::class);
        $service->submit(new HistoricalRateBackfillRequest($recalculation->id, [[
            'requirement_key' => $requirement['requirement_key'],
            'buying_open' => '1', 'buying_close' => '1', 'selling_open' => '1', 'selling_close' => '1',
        ]]));
    }

    public function test_abort_restores_previous_currency_and_cancels_recalculation(): void
    {
        Queue::fake();
        [$tenant, $recalculation, $setting] = $this->waitingRecalculation('abort', true);

        $service = app(ReportingCurrencyRecalculationService::class);
        $restored = $service->abort(
            $tenant->id,
            $recalculation->id,
            $setting->update_key,
        );

        $this->assertSame($recalculation->previous_reporting_currency_id, $restored->reporting_currency_id);
        $this->assertSame($setting->update_key + 1, $restored->update_key);
        $this->assertSame('cancelled', $recalculation->refresh()->status);
        $this->assertNotNull($recalculation->cancelled_at);
        $service->retryPendingForTenant($tenant->id);
        $service->markFailed($recalculation->id, 'Late worker failure');
        $service->process($recalculation->id);
        $this->assertSame('cancelled', $recalculation->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_normal_tenant_rate_creation_rejects_past_effective_date(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-15 12:00:00', 'Asia/Yangon'));
        $tenant = $this->tenant('past-rate');
        app(TenantContext::class)->set($tenant->id);

        $this->expectException(InvalidTenantRequest::class);
        app(TenantExchangeRateService::class)->create(new StoreExchangeRateRequest(
            pairCode: 'USD-MMK', buyingRate: '3500', sellingRate: '3520', idempotencyKey: null, effectiveDate: '2026-08-14',
        ));
    }

    private function waitingRecalculation(string $code, bool $includeSetting = false): array
    {
        $this->seed(CurrencySeeder::class);
        $this->seed(ExchangeRatePairSeeder::class);
        $tenant = $this->tenant($code);
        $mmk = Currency::query()->where('code', 'MMK')->whereNull('tenant_id')->firstOrFail();
        $usd = Currency::query()->where('code', 'USD')->whereNull('tenant_id')->firstOrFail();
        $setting = TenantSetting::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'key' => 'currency_preferences',
            'category' => 'finance',
            'default_currency_id' => $mmk->id,
            'reporting_currency_id' => $usd->id,
            'update_key' => 4,
        ]);
        $recalculation = ReportingCurrencyRecalculation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'previous_reporting_currency_id' => $mmk->id,
            'requested_reporting_currency_id' => $usd->id,
            'window_start' => '2026-06-01',
            'window_end' => '2026-08-15',
            'status' => 'waiting_for_rates',
            'missing_rates' => [['date' => '2026-07-15', 'from_currency_id' => $mmk->id, 'to_currency_id' => $usd->id]],
        ])->load(['previousReportingCurrency', 'requestedReportingCurrency']);

        return $includeSetting ? [$tenant, $recalculation, $setting] : [$tenant, $recalculation];
    }

    private function tenant(string $code): Tenant
    {
        $owner = PlatformUser::query()->create([
            'code' => 'PU'.strtoupper($code),
            'name' => 'Test Owner',
            'email' => "{$code}@example.com",
            'phone' => '09111111111',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        return Tenant::query()->create([
            'platform_user_id' => $owner->id,
            'name' => 'Test Tenant',
            'tenant_code' => $code,
            'subdomain' => $code,
            'status' => 'active',
        ]);
    }
}
