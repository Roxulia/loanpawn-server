<?php

namespace Database\Seeders;

use App\Models\CoreModule\Currency;
use App\Models\CoreModule\ExchangeRatePair;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ExchangeRatePairSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (config('finance.exchange_pairs', []) as $definition) {
                $baseCode = strtoupper(trim((string) ($definition['base'] ?? '')));
                $quoteCode = strtoupper(trim((string) ($definition['quote'] ?? '')));
                $base = Currency::query()->where('scope_key', 'platform')->where('code', $baseCode)->first();
                $quote = Currency::query()->where('scope_key', 'platform')->where('code', $quoteCode)->first();

                if (! $base || ! $quote) {
                    throw new RuntimeException("Default exchange pair {$baseCode}/{$quoteCode} references an unknown currency.");
                }

                $code = "{$baseCode}-{$quoteCode}";
                $pair = ExchangeRatePair::withTrashed()->firstOrNew(['scope_key' => 'platform', 'code' => $code]);
                $pair->fill([
                    'tenant_id' => null,
                    'base_currency_id' => $base->id,
                    'quote_currency_id' => $quote->id,
                    'is_default' => true,
                    'is_active' => true,
                ]);
                $pair->deleted_at = null;
                $pair->save();
            }
        });
    }
}
