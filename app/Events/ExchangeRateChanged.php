<?php

namespace App\Events;

use App\Models\CoreModule\ExchangeRateEntry;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExchangeRateChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $scopeKey,
        public readonly ?int $tenantId,
        public readonly int $pairId,
        public readonly string $effectiveDate,
    ) {}

    public static function fromEntry(ExchangeRateEntry $entry): self
    {
        return new self(
            scopeKey: $entry->scope_key,
            tenantId: $entry->tenant_id === null ? null : (int) $entry->tenant_id,
            pairId: (int) $entry->exchange_rate_pair_id,
            effectiveDate: $entry->effective_date->toDateString(),
        );
    }
}
