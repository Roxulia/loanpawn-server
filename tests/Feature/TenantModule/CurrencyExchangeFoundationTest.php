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
use Carbon\CarbonImmutable;
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
        $writer->create($pair, ['buying_rate' => '3500', 'selling_rate' => '3520'], null, null, null, CarbonImmutable::parse('2026-08-10 09:00:00', 'Asia/Yangon'));
        $writer->create($pair, ['buying_rate' => '3550', 'selling_rate' => '3570'], null, null, null, CarbonImmutable::parse('2026-08-10 11:00:00', 'Asia/Yangon'));
        $writer->create($pair, ['buying_rate' => '3450', 'selling_rate' => '3490'], null, null, null, CarbonImmutable::parse('2026-08-10 14:00:00', 'Asia/Yangon'));

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

    public function test_correction_voids_original_preserves_audit_and_rebuilds_close(): void
    {
        $pair = $this->defaultPair();
        $entry = app(ExchangeRateEntryWriter::class)->create($pair, ['buying_rate' => '3500', 'selling_rate' => '3520'], null, null, null, CarbonImmutable::parse('2026-08-10 09:00:00', 'Asia/Yangon'));
        $replacement = app(ExchangeRateCorrectionService::class)->correct($entry, '3600', '3620', 'Correct data entry mistake', null, null);

        $this->assertTrue($entry->refresh()->is_void);
        $this->assertSame('3600.000000000000', $replacement->buying_rate);
        $this->assertSame('3620.000000000000', $replacement->selling_rate);
        $this->assertDatabaseHas('exchange_rate_corrections', ['original_entry_id' => $entry->id, 'replacement_entry_id' => $replacement->id, 'action' => 'CORRECT']);
        $this->assertDatabaseHas('daily_exchange_rate_summaries', ['exchange_rate_pair_id' => $pair->id, 'entry_count' => 1, 'buying_close' => '3600.000000000000', 'selling_close' => '3620.000000000000']);
    }

    private function defaultPair(): ExchangeRatePair
    {
        $this->seed(CurrencySeeder::class);
        $this->seed(ExchangeRatePairSeeder::class);

        return ExchangeRatePair::query()->where('code', 'USD-MMK')->firstOrFail();
    }
}
