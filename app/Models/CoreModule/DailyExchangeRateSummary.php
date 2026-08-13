<?php

namespace App\Models\CoreModule;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyExchangeRateSummary extends Model
{
    protected $fillable = ['tenant_id', 'scope_key', 'exchange_rate_pair_id', 'rate_date', 'buying_open', 'buying_high', 'buying_low', 'buying_close', 'selling_open', 'selling_high', 'selling_low', 'selling_close', 'entry_count', 'first_entry_id', 'last_entry_id', 'calculated_at'];

    protected function casts(): array
    {
        return ['rate_date' => 'date', 'buying_open' => 'decimal:12', 'buying_high' => 'decimal:12', 'buying_low' => 'decimal:12', 'buying_close' => 'decimal:12', 'selling_open' => 'decimal:12', 'selling_high' => 'decimal:12', 'selling_low' => 'decimal:12', 'selling_close' => 'decimal:12', 'entry_count' => 'integer', 'calculated_at' => 'datetime'];
    }

    public function pair(): BelongsTo
    {
        return $this->belongsTo(ExchangeRatePair::class, 'exchange_rate_pair_id')->withTrashed();
    }

    public function firstEntry(): BelongsTo
    {
        return $this->belongsTo(ExchangeRateEntry::class, 'first_entry_id');
    }

    public function lastEntry(): BelongsTo
    {
        return $this->belongsTo(ExchangeRateEntry::class, 'last_entry_id');
    }
}
