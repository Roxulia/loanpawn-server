<?php

namespace Tests\Feature\TenantModule;

use App\DataObjects\RequestObjects\StoreExchangeRateRequest;
use App\Events\ExchangeRateChanged;
use App\Models\CoreModule\Currency;
use App\Models\CoreModule\DailyExchangeRateSummary;
use App\Models\CoreModule\ExchangeRateEntry;
use App\Models\CoreModule\ExchangeRatePair;
use App\Services\ExchangeRate\ExchangeRateCorrectionService;
use App\Services\ExchangeRate\ExchangeRateEntryWriter;
use App\Services\PlatformModule\AdminExchangeRateService;
use Carbon\CarbonImmutable;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ExchangeRatePairSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CurrencyExchangeFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_configured_default_currencies_and_pairs_are_seeded_idempotently(): void
    {
        $this->seed(CurrencySeeder::class);
        $this->seed(ExchangeRatePairSeeder::class);
        $this->seed(CurrencySeeder::class);
        $this->seed(ExchangeRatePairSeeder::class);

        $this->assertSame('MMK', config('finance.default_currency'));
        $this->assertSame(3, Currency::query()->where('scope_key', 'platform')->count());
        $this->assertSame(['JPY', 'MMK', 'USD'], Currency::query()->where('scope_key', 'platform')->orderBy('code')->pluck('code')->all());
        $this->assertSame(['JPY-MMK', 'USD-MMK'], ExchangeRatePair::query()->where('scope_key', 'platform')->orderBy('code')->pluck('code')->all());
        $this->assertSame(0, ExchangeRateEntry::query()->count());
    }

    public function test_exchange_rate_events_build_daily_ohlc(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 08:00:00', 'UTC'));
        $pair = $this->defaultPair();
        $writer = app(ExchangeRateEntryWriter::class);
        $this->record($writer, $pair, '3500', '3520', '2026-08-10 09:00:00');
        $this->record($writer, $pair, '3550', '3570', '2026-08-10 11:00:00');
        $this->record($writer, $pair, '3450', '3490', '2026-08-10 14:00:00');

        $summary = DailyExchangeRateSummary::query()->firstOrFail();
        $this->assertSame('3500.000000000000', $summary->buying_open);
        $this->assertSame('3550.000000000000', $summary->buying_high);
        $this->assertSame('3450.000000000000', $summary->buying_low);
        $this->assertSame('3450.000000000000', $summary->buying_close);
        $this->assertSame('3520.000000000000', $summary->selling_open);
        $this->assertSame('3570.000000000000', $summary->selling_high);
        $this->assertSame('3490.000000000000', $summary->selling_low);
        $this->assertSame('3490.000000000000', $summary->selling_close);
        $this->assertSame(3, $summary->entry_count);
    }

    public function test_correction_event_uses_latest_active_rate_as_close(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 08:00:00', 'UTC'));
        $pair = $this->defaultPair();
        $entry = app(ExchangeRateEntryWriter::class)->create($pair, ['buying_rate' => '3500', 'selling_rate' => '3520'], null, null, null, CarbonImmutable::parse('2026-08-10 09:00:00', 'Asia/Yangon'));
        $replacement = app(ExchangeRateCorrectionService::class)->correct($entry, '3600', '3620', 'Correct data entry mistake', null, null);

        $this->assertTrue($entry->refresh()->is_void);
        $this->assertSame('3600.000000000000', $replacement->buying_rate);
        $this->assertSame('3620.000000000000', $replacement->selling_rate);
        $this->assertDatabaseHas('exchange_rate_corrections', ['original_entry_id' => $entry->id, 'replacement_entry_id' => $replacement->id, 'action' => 'CORRECT']);
        $this->assertDatabaseHas('daily_exchange_rate_summaries', ['exchange_rate_pair_id' => $pair->id, 'entry_count' => 1, 'buying_close' => '3600.000000000000', 'selling_close' => '3620.000000000000']);
    }

    public function test_void_event_deletes_summary_when_all_entries_are_void(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 08:00:00', 'UTC'));
        $pair = $this->defaultPair();
        $entry = app(ExchangeRateEntryWriter::class)->create($pair, ['buying_rate' => '3500', 'selling_rate' => '3520'], null, null, null, CarbonImmutable::parse('2026-08-10 09:00:00', 'Asia/Yangon'));
        event(ExchangeRateChanged::fromEntry($entry));

        $this->assertDatabaseCount('daily_exchange_rate_summaries', 1);

        app(ExchangeRateCorrectionService::class)->void($entry, 'Invalid rate', null, null);
        $this->assertDatabaseCount('daily_exchange_rate_summaries', 0);
    }

    public function test_historical_correction_event_rebuilds_the_affected_day(): void
    {
        $pair = $this->defaultPair();
        $entry = app(ExchangeRateEntryWriter::class)->create($pair, ['buying_rate' => '3500', 'selling_rate' => '3520'], null, null, null, CarbonImmutable::parse('2026-08-10 23:30:00', 'Asia/Yangon'));
        event(ExchangeRateChanged::fromEntry($entry));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 18:00:00', 'UTC'));
        app(ExchangeRateCorrectionService::class)->correct($entry, '3600', '3620', 'Late correction', null, null);

        $summary = DailyExchangeRateSummary::query()->firstOrFail();
        $this->assertSame('3600.000000000000', $summary->buying_close);
        $this->assertSame('3620.000000000000', $summary->selling_close);
    }

    public function test_idempotent_create_dispatches_only_for_the_new_entry(): void
    {
        Event::fake([ExchangeRateChanged::class]);
        $this->defaultPair();
        $request = new StoreExchangeRateRequest('USD-MMK', '3500', '3520', 'rate-request-1');
        $service = app(AdminExchangeRateService::class);

        $service->create($request);
        $service->create($request);

        $this->assertDatabaseCount('exchange_rate_entries', 1);
        Event::assertDispatchedTimes(ExchangeRateChanged::class, 1);
    }

    private function defaultPair(): ExchangeRatePair
    {
        $this->seed(CurrencySeeder::class);
        $this->seed(ExchangeRatePairSeeder::class);

        return ExchangeRatePair::query()->where('code', 'USD-MMK')->firstOrFail();
    }

    private function record(ExchangeRateEntryWriter $writer, ExchangeRatePair $pair, string $buyingRate, string $sellingRate, string $observedAt): void
    {
        $entry = $writer->create(
            $pair,
            ['buying_rate' => $buyingRate, 'selling_rate' => $sellingRate],
            null,
            null,
            null,
            CarbonImmutable::parse($observedAt, 'Asia/Yangon'),
        );
        event(ExchangeRateChanged::fromEntry($entry));
    }
}
