<?php

namespace App\Http\Resources\Finance;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DailyExchangeRateSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'rate_date' => $this->rate_date?->toDateString(), 'pair' => new ExchangeRatePairResource($this->pair), 'open_rate' => $this->open_rate, 'high_rate' => $this->high_rate, 'low_rate' => $this->low_rate, 'close_rate' => $this->close_rate, 'entry_count' => $this->entry_count, 'calculated_at' => $this->calculated_at?->toIso8601String(), 'source' => $this->tenant_id === null ? 'PLATFORM' : 'TENANT'];
    }
}
