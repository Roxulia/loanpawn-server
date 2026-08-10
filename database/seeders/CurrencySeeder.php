<?php

namespace Database\Seeders;

use App\Models\CoreModule\Currency;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $definitions = config('finance.currencies', []);
        $codes = array_map(fn (array $row) => strtoupper((string) ($row['code'] ?? '')), $definitions);
        $default = strtoupper((string) config('finance.default_currency'));

        if ($default === '' || ! in_array($default, $codes, true)) {
            throw new RuntimeException('finance.default_currency must exist in finance.currencies.');
        }

        DB::transaction(function () use ($definitions): void {
            foreach ($definitions as $definition) {
                $code = strtoupper(trim((string) $definition['code']));
                $currency = Currency::withTrashed()->firstOrNew(['scope_key' => 'platform', 'code' => $code]);
                $currency->fill([
                    'tenant_id' => null,
                    'name' => $definition['name'],
                    'symbol' => $definition['symbol'] ?? null,
                    'decimal_precision' => $definition['decimal_precision'] ?? 2,
                    'rounding_mode' => $definition['rounding_mode'] ?? 'HALF_UP',
                    'adjustment_step' => $definition['adjustment_step'] ?? null,
                    'is_default' => true,
                    'is_active' => true,
                ]);
                $currency->deleted_at = null;
                $currency->save();
            }
        });
    }
}
