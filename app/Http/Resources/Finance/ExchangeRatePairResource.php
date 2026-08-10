<?php

namespace App\Http\Resources\Finance;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExchangeRatePairResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'display_code' => "{$this->baseCurrency->code}/{$this->quoteCurrency->code}", 'base_currency' => new CurrencyResource($this->baseCurrency), 'quote_currency' => new CurrencyResource($this->quoteCurrency), 'is_default' => $this->is_default, 'is_active' => $this->is_active, 'source' => $this->tenant_id === null ? 'PLATFORM' : 'TENANT', 'can_update' => $this->tenant_id !== null, 'can_delete' => $this->tenant_id !== null, 'update_key' => $this->update_key];
    }
}
