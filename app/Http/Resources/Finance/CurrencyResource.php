<?php

namespace App\Http\Resources\Finance;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurrencyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'name' => $this->name, 'symbol' => $this->symbol, 'decimal_precision' => $this->decimal_precision, 'rounding_mode' => $this->rounding_mode, 'adjustment_step' => $this->adjustment_step, 'is_default' => $this->is_default, 'is_active' => $this->is_active, 'source' => $this->tenant_id === null ? 'PLATFORM' : 'TENANT', 'can_update' => $this->tenant_id !== null, 'can_delete' => $this->tenant_id !== null, 'update_key' => $this->update_key];
    }
}
