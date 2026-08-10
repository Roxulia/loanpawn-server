<?php

namespace App\Http\Resources\Finance;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExchangeRateEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'pair' => new ExchangeRatePairResource($this->whenLoaded('pair', $this->pair)), 'rate' => $this->rate, 'effective_date' => $this->effective_date?->toDateString(), 'observed_at' => $this->observed_at?->toIso8601String(), 'source' => $this->source, 'is_void' => $this->is_void, 'voided_at' => $this->voided_at?->toIso8601String(), 'void_reason' => $this->void_reason, 'can_correct' => $this->tenant_id !== null && ! $this->is_void, 'can_void' => $this->tenant_id !== null && ! $this->is_void];
    }
}
