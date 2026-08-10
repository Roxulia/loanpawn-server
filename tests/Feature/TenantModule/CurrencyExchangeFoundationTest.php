<?php

namespace Tests\Feature\TenantModule;

use App\Models\CoreModule\Currency;
use App\Models\CoreModule\DailyExchangeRateSummary;
use App\Models\CoreModule\ExchangeRateEntry;
use App\Models\CoreModule\ExchangeRatePair;
use App\Services\ExchangeRate\ExchangeRateCorrectionService;
use App\Services\ExchangeRate\ExchangeRateEntryWriter;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ExchangeRatePairSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyExchangeFoundationTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_sequential_rate_entries_build_daily_ohlc(): void
    {
        $pair = $this->defaultPair();
        $writer = app(ExchangeRateEntryWriter::class);
        $writer->create($pair, ['rate' => '3500.000000000000', 'observed_at' => '2026-08-10 09:00:00'], null, null, null);
        $writer->create($pair, ['rate' => '3550.000000000000', 'observed_at' => '2026-08-10 11:00:00'], null, null, null);
        $writer->create($pair, ['rate' => '3450.000000000000', 'observed_at' => '2026-08-10 14:00:00'], null, null, null);

        $summary = DailyExchangeRateSummary::query()->firstOrFail();
        $this->assertSame('3500.000000000000', $summary->open_rate);
        $this->assertSame('3550.000000000000', $summary->high_rate);
        $this->assertSame('3450.000000000000', $summary->low_rate);
        $this->assertSame('3450.000000000000', $summary->close_rate);
        $this->assertSame(3, $summary->entry_count);
    }

    public function test_correction_voids_original_preserves_audit_and_rebuilds_close(): void
    {
        $pair = $this->defaultPair();
        $entry = app(ExchangeRateEntryWriter::class)->create($pair, ['rate' => '3500', 'observed_at' => '2026-08-10 09:00:00'], null, null, null);
        $replacement = app(ExchangeRateCorrectionService::class)->correct($entry, '3600', 'Correct data entry mistake', null, null);

        $this->assertTrue($entry->refresh()->is_void);
        $this->assertSame('3600.000000000000', $replacement->rate);
        $this->assertDatabaseHas('exchange_rate_corrections', ['original_entry_id' => $entry->id, 'replacement_entry_id' => $replacement->id, 'action' => 'CORRECT']);
        $this->assertDatabaseHas('daily_exchange_rate_summaries', ['exchange_rate_pair_id' => $pair->id, 'entry_count' => 1, 'close_rate' => '3600.000000000000']);
    }

    private function defaultPair(): ExchangeRatePair
    {
        $this->seed(CurrencySeeder::class);
        $this->seed(ExchangeRatePairSeeder::class);

        return ExchangeRatePair::query()->where('code', 'USD-MMK')->firstOrFail();
    }
}
