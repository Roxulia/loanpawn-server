<?php

namespace App\Models\CoreModule;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyExchangeRateSummary extends Model
{
    protected $fillable = ['tenant_id', 'scope_key', 'exchange_rate_pair_id', 'rate_date', 'open_rate', 'high_rate', 'low_rate', 'close_rate', 'entry_count', 'first_entry_id', 'last_entry_id', 'calculated_at'];

    protected function casts(): array
    {
        return ['rate_date' => 'date', 'open_rate' => 'decimal:12', 'high_rate' => 'decimal:12', 'low_rate' => 'decimal:12', 'close_rate' => 'decimal:12', 'entry_count' => 'integer', 'calculated_at' => 'datetime'];
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
